<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\UniqueConstraint(name: 'uniq_device_token', columns: ['token'])]
#[ORM\HasLifecycleCallbacks]
class Device
{
    public const int TOKEN_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nom/label libre du dispositif pour s'y retrouver côté admin.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    /**
     * Jeton d'authentification du Raspberry Pi auprès de l'API.
     */
    #[ORM\Column(type: Types::STRING, length: self::TOKEN_MAX_LENGTH)]
    private string $token;

    /**
     * Permet de couper l'accès d'un dispositif sans le supprimer.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Configurations d'affichage jouées par ce dispositif, en rotation si plusieurs.
     *
     * @var Collection<int, DisplayConfiguration>
     */
    #[ORM\OneToMany(targetEntity: DisplayConfiguration::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $displayConfigurations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Jeton généré si aucun n'est fourni. En imposer un sert aux fixtures, pour
     * un appareil de développement au jeton stable.
     */
    public function __construct(string $label, ?string $token = null)
    {
        if (null !== $token && ('' === $token || \strlen($token) > self::TOKEN_MAX_LENGTH)) {
            throw new \InvalidArgumentException(\sprintf('Le jeton doit faire entre 1 et %d caractères, %d reçus.', self::TOKEN_MAX_LENGTH, \strlen($token)));
        }

        $this->label = $label;
        $this->token = $token ?? Uuid::v4()->toRfc4122();
        $this->displayConfigurations = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getToken(): string
    {
        return $this->token;
    }

    public function regenerateToken(): static
    {
        $this->token = Uuid::v4()->toRfc4122();

        return $this;
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

    /**
     * @return Collection<int, DisplayConfiguration>
     */
    public function getDisplayConfigurations(): Collection
    {
        return $this->displayConfigurations;
    }

    /**
     * Configurations à afficher, dans l'ordre de rotation (position croissante).
     *
     * @return list<DisplayConfiguration>
     */
    public function getActiveDisplayConfigurations(): array
    {
        $configurations = array_values(
            $this->displayConfigurations
                ->filter(static fn (DisplayConfiguration $configuration): bool => $configuration->isActive())
                ->toArray(),
        );

        usort(
            $configurations,
            static fn (DisplayConfiguration $a, DisplayConfiguration $b): int => $a->getPosition() <=> $b->getPosition(),
        );

        return $configurations;
    }

    public function addDisplayConfiguration(DisplayConfiguration $configuration): static
    {
        if (!$this->displayConfigurations->contains($configuration)) {
            $this->displayConfigurations->add($configuration);
            $configuration->setDevice($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
