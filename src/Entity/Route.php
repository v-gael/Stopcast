<?php

namespace App\Entity;

use App\Repository\RouteRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RouteRepository::class)]
#[ORM\Table(name: 'route')]
#[ORM\UniqueConstraint(name: 'uniq_route_gtfs_route_id', columns: ['gtfs_route_id'])]
#[ORM\HasLifecycleCallbacks]
class Route
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * route_id du GTFS, référencé tel quel dans le flux GTFS-RT
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $gtfsRouteId;

    /**
     * route_short_name : le numéro/code affiché aux usagers
     */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $shortName;

    /**
     * route_long_name : les deux terminaux de la ligne (ex: "CENTRE VILLE-GARE NORD")
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $longName = null;

    /**
     * route_color : couleur de fond hexadécimale sans '#' (ex: "F29400") telle que définie par l'exploitant
     * Reflète la couleur réelle affichée sur les bus/panneaux du réseau.
     */
    #[ORM\Column(type: Types::STRING, length: 6)]
    private string $color;

    /**
     * route_text_color : couleur du texte hexadécimale sans '#' (ex: "000000").
     */
    #[ORM\Column(type: Types::STRING, length: 6)]
    private string $textColor;

    /**
     * false si la ligne a disparu d'un import GTFS ultérieur
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deprecatedAt = null;

    /**
     * Quais desservis par cette ligne (relation inverse, portée par Quay)
     *
     * @var Collection<int, Quay>
     */
    #[ORM\ManyToMany(targetEntity: Quay::class, mappedBy: 'routes')]
    private Collection $quays;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $gtfsRouteId, string $shortName, string $color, string $textColor)
    {
        $this->gtfsRouteId = $gtfsRouteId;
        $this->shortName = $shortName;
        $this->color = $color;
        $this->textColor = $textColor;
        $this->quays = new ArrayCollection();
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

    public function getGtfsRouteId(): string
    {
        return $this->gtfsRouteId;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
    }

    public function getLongName(): ?string
    {
        return $this->longName;
    }

    public function setLongName(?string $longName): static
    {
        $this->longName = $longName;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getTextColor(): string
    {
        return $this->textColor;
    }

    public function setTextColor(string $textColor): static
    {
        $this->textColor = $textColor;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function markDeprecated(): static
    {
        $this->active = false;
        $this->deprecatedAt = new DateTimeImmutable();

        return $this;
    }

    public function markActive(): static
    {
        $this->active = true;
        $this->deprecatedAt = null;

        return $this;
    }

    public function getDeprecatedAt(): ?DateTimeImmutable
    {
        return $this->deprecatedAt;
    }

    /**
     * @return Collection<int, Quay>
     */
    public function getQuays(): Collection
    {
        return $this->quays;
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
