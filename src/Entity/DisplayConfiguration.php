<?php

namespace App\Entity;

use App\Repository\DisplayConfigurationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DisplayConfigurationRepository::class)]
#[ORM\Table(name: 'display_configuration')]
#[ORM\Index(name: 'idx_display_configuration_device', columns: ['device_id'])]
#[ORM\HasLifecycleCallbacks]
class DisplayConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'displayConfigurations')]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: false)]
    private Device $device;

    /**
     * Le quai dont ce dispositif doit afficher les prochains passages
     */
    #[ORM\ManyToOne(targetEntity: Quay::class)]
    #[ORM\JoinColumn(name: 'quay_id', referencedColumnName: 'id', nullable: false)]
    private Quay $quay;

    /**
     * Nom libre donné par l'utilisateur à cette configuration
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    /**
     * Ordre d'affichage en cas de rotation entre plusieurs
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    /**
     * Permet de désactiver temporairement une config sans la supprimer
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(Device $device, Quay $quay)
    {
        $this->device = $device;
        $this->quay = $quay;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
