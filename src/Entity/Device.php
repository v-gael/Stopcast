<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\UniqueConstraint(name: 'uniq_device_token', columns: ['token'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['device:read']],
        ),
        new Get(
            normalizationContext: ['groups' => ['device:read']],
        ),
        new Post(
            normalizationContext: ['groups' => ['device:read', 'device:read:created']],
            denormalizationContext: ['groups' => ['device:write']],
        ),
        new Patch(
            normalizationContext: ['groups' => ['device:read']],
            denormalizationContext: ['groups' => ['device:write']],
        ),
        new Delete(),
    ],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['label' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['device:read'])]
    private ?int $id = null;

    /**
     * Nom/label libre du dispositif pour s'y retrouver côté admin.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['device:read', 'device:write'])]
    private string $label;

    /**
     * Jeton d'authentification du Raspberry Pi auprès de l'API.
     * Exposé uniquement à la création (groupe device:read:created), jamais en lecture/écriture normale.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Groups(['device:read:created'])]
    private string $token;

    /**
     * Permet de couper l'accès d'un dispositif sans le supprimer.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['device:read', 'device:write'])]
    private bool $active = true;

    /**
     * Configurations d'affichage jouées par ce dispositif, en rotation si plusieurs.
     *
     * @var Collection<int, DisplayConfiguration>
     */
    #[ORM\OneToMany(targetEntity: DisplayConfiguration::class, mappedBy: 'device', orphanRemoval: true)]
    #[Groups(['device:read'])]
    private Collection $displayConfigurations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['device:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['device:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->token = Uuid::v4()->toRfc4122();
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
