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
use App\Repository\DisplayConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DisplayConfigurationRepository::class)]
#[ORM\Table(name: 'display_configuration')]
#[ORM\Index(name: 'idx_display_configuration_device', columns: ['device_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['display_config:read']]
        ),
        new Get(
            normalizationContext: ['groups' => ['display_config:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['display_config:read']],
            denormalizationContext: ['groups' => ['display_config:write']],
        ),
        new Patch(
            normalizationContext: ['groups' => ['display_config:read']],
            denormalizationContext: ['groups' => ['display_config:update']],
        ),
        new Delete(),
    ],
    order: ['position' => 'ASC'],
)]
// Permet de filtrer /api/display_configurations?device=/api/devices/1 (lister les configs d'un dispositif)
#[ApiFilter(SearchFilter::class, properties: ['device' => 'exact', 'quay' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class DisplayConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['display_config:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'displayConfigurations')]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['display_config:read', 'display_config:write'])]
    private Device $device;

    /**
     * Le quai dont ce dispositif doit afficher les prochains passages.
     */
    #[ORM\ManyToOne(targetEntity: Quay::class)]
    #[ORM\JoinColumn(name: 'quay_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['display_config:read', 'display_config:write', 'display_config:update'])]
    private Quay $quay;

    /**
     * Nom libre donné par l'utilisateur à cette configuration.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['display_config:read', 'display_config:write', 'display_config:update'])]
    private ?string $label = null;

    /**
     * Ordre d'affichage en cas de rotation entre plusieurs.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['display_config:read', 'display_config:write', 'display_config:update'])]
    private int $position = 0;

    /**
     * Permet de désactiver temporairement une config sans la supprimer.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['display_config:read', 'display_config:write', 'display_config:update'])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['display_config:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['display_config:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Device $device, Quay $quay)
    {
        $this->device = $device;
        $this->quay = $quay;
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

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function setDevice(Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getQuay(): Quay
    {
        return $this->quay;
    }

    public function setQuay(Quay $quay): static
    {
        $this->quay = $quay;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
