<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeviceStatus;
use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\UniqueConstraint(name: 'uniq_device_screen_secret_hash', columns: ['screen_secret_hash'])]
#[ORM\HasLifecycleCallbacks]
class Device
{
    public const int DEFAULT_SCREEN_DURATION = 5;

    public const int MIN_SCREEN_DURATION = 3;

    public const int MAX_SCREEN_DURATION = 60;

    public const int DEFAULT_REFRESH_INTERVAL = 60;

    public const int MIN_REFRESH_INTERVAL = 30;

    public const int MAX_REFRESH_INTERVAL = 300;

    /**
     * Écart minimal entre deux écritures du dernier contact : l'écran se
     * rafraîchit toutes les 30 à 300 s, la base n'en garde qu'un toutes les
     * 5 minutes.
     */
    public const string CONTACT_RECORD_INTERVAL = '5 minutes';

    /**
     * Sans contact depuis ce délai, un écran appairé est hors ligne :
     * rafraîchissement le plus long (5 min), plus l'écart entre deux écritures
     * (5 min), plus une marge.
     */
    public const string OFFLINE_AFTER = '15 minutes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Seul compte qui voit et gère ce dispositif. Sa suppression emporte le
     * dispositif et ses configurations, au niveau de la base.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /**
     * Nom/label libre du dispositif pour s'y retrouver côté admin.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    /**
     * Haché SHA-256 du secret de l'écran appairé, que l'écran garde dans son
     * cookie ; null tant qu'aucun écran n'est appairé. Le secret en clair n'est
     * jamais stocké.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $screenSecretHash = null;

    /**
     * Permet de couper l'accès d'un dispositif sans le supprimer.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Durée d'un écran de la page d'affichage, en secondes, avant de passer au suivant.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_SCREEN_DURATION])]
    #[Assert\Range(min: self::MIN_SCREEN_DURATION, max: self::MAX_SCREEN_DURATION)]
    private int $screenDuration = self::DEFAULT_SCREEN_DURATION;

    /**
     * Intervalle, en secondes, entre deux rafraîchissements des données de la page d'affichage.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_REFRESH_INTERVAL])]
    #[Assert\Range(min: self::MIN_REFRESH_INTERVAL, max: self::MAX_REFRESH_INTERVAL)]
    private int $refreshInterval = self::DEFAULT_REFRESH_INTERVAL;

    /**
     * Configurations d'affichage jouées par ce dispositif, en rotation si plusieurs.
     *
     * @var Collection<int, DisplayConfiguration>
     */
    #[ORM\OneToMany(targetEntity: DisplayConfiguration::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $displayConfigurations;

    /**
     * Dernière requête de l'écran appairé, à CONTACT_RECORD_INTERVAL près ; null tant que
     * l'écran n'a jamais appelé. Dissocier ou réappairer la garde.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $label)
    {
        $this->owner = $owner;
        $this->label = $label;
        $this->displayConfigurations = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Un simple contact de l'écran n'est pas une modification du dispositif.
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(PreUpdateEventArgs $event): void
    {
        if (['lastSeenAt'] === array_keys($event->getEntityChangeSet())) {
            return;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Haché sous lequel le secret d'un écran est stocké et recherché.
     */
    public static function hashScreenSecret(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Appaire l'écran qui détient ce secret : un dispositif n'a qu'un écran,
     * l'écran précédent, s'il y en avait un, ne s'authentifie plus.
     */
    public function pairScreen(#[\SensitiveParameter] string $secret): static
    {
        $this->screenSecretHash = self::hashScreenSecret($secret);

        return $this;
    }

    public function unpairScreen(): static
    {
        $this->screenSecretHash = null;

        return $this;
    }

    public function hasPairedScreen(): bool
    {
        return null !== $this->screenSecretHash;
    }

    public function getScreenSecretHash(): ?string
    {
        return $this->screenSecretHash;
    }

    /**
     * Note une requête de l'écran, seulement si la précédente notée date de
     * plus de CONTACT_RECORD_INTERVAL : dit si la date a changé, donc s'il
     * faut écrire.
     */
    public function recordContact(\DateTimeImmutable $now): bool
    {
        if ($this->lastSeenAt instanceof \DateTimeImmutable
            && $this->lastSeenAt > $now->modify('-'.self::CONTACT_RECORD_INTERVAL)) {
            return false;
        }

        $this->lastSeenAt = $now;

        return true;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Ce qui demande l'attention du propriétaire : pas d'écran appairé, ou un
     * écran sans dernier contact depuis plus de OFFLINE_AFTER (ou jamais).
     * Rien pour un dispositif désactivé.
     */
    public function status(\DateTimeImmutable $now): ?DeviceStatus
    {
        if (!$this->active) {
            return null;
        }

        if (!$this->hasPairedScreen()) {
            return DeviceStatus::Unpaired;
        }

        if (!$this->lastSeenAt instanceof \DateTimeImmutable || $this->lastSeenAt < $now->modify('-'.self::OFFLINE_AFTER)) {
            return DeviceStatus::Offline;
        }

        return null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getScreenDuration(): int
    {
        return $this->screenDuration;
    }

    public function setScreenDuration(int $screenDuration): static
    {
        $this->screenDuration = $screenDuration;

        return $this;
    }

    public function getRefreshInterval(): int
    {
        return $this->refreshInterval;
    }

    public function setRefreshInterval(int $refreshInterval): static
    {
        $this->refreshInterval = $refreshInterval;

        return $this;
    }

    /**
     * @return Collection<int, DisplayConfiguration>
     */
    public function getDisplayConfigurations(): Collection
    {
        return $this->displayConfigurations;
    }

    /**
     * Toutes les configurations, masquées comprises, dans l'ordre de rotation.
     * À position égale, l'ordre d'ajout départage.
     *
     * @return list<DisplayConfiguration>
     */
    public function getDisplayConfigurationsInRotationOrder(): array
    {
        $configurations = array_values($this->displayConfigurations->toArray());

        usort(
            $configurations,
            static fn (DisplayConfiguration $a, DisplayConfiguration $b): int => $a->getPosition() <=> $b->getPosition(),
        );

        return $configurations;
    }

    /**
     * Configurations à afficher, dans l'ordre de rotation (position croissante).
     *
     * @return list<DisplayConfiguration>
     */
    public function getActiveDisplayConfigurations(): array
    {
        return array_values(array_filter(
            $this->getDisplayConfigurationsInRotationOrder(),
            static fn (DisplayConfiguration $configuration): bool => $configuration->isActive(),
        ));
    }

    public function addDisplayConfiguration(DisplayConfiguration $configuration): static
    {
        if (!$this->displayConfigurations->contains($configuration)) {
            $this->displayConfigurations->add($configuration);
            $configuration->setDevice($this);
        }

        return $this;
    }

    /**
     * Ajoute la configuration après toutes les autres, masquées comprises.
     */
    public function appendDisplayConfiguration(DisplayConfiguration $configuration): static
    {
        $positions = $this->displayConfigurations->map(static fn (DisplayConfiguration $existing): int => $existing->getPosition())->toArray();
        $configuration->setPosition([] === $positions ? 0 : max($positions) + 1);

        return $this->addDisplayConfiguration($configuration);
    }

    public function moveDisplayConfigurationUp(DisplayConfiguration $configuration): static
    {
        return $this->moveDisplayConfiguration($configuration, -1);
    }

    public function moveDisplayConfigurationDown(DisplayConfiguration $configuration): static
    {
        return $this->moveDisplayConfiguration($configuration, 1);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Échange la configuration avec sa voisine puis renumérote toute la rotation
     * à partir de 0 : des positions en double ne bloquent pas l'échange.
     */
    private function moveDisplayConfiguration(DisplayConfiguration $configuration, int $offset): static
    {
        $rotation = $this->getDisplayConfigurationsInRotationOrder();
        $index = array_search($configuration, $rotation, true);

        if (false === $index || !isset($rotation[$index + $offset])) {
            return $this;
        }

        [$rotation[$index], $rotation[$index + $offset]] = [$rotation[$index + $offset], $rotation[$index]];

        foreach ($rotation as $position => $each) {
            $each->setPosition($position);
        }

        return $this;
    }
}
