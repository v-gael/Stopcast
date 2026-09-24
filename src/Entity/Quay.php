<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\QuayRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: QuayRepository::class)]
#[ORM\Table(name: 'quay')]
#[ORM\UniqueConstraint(name: 'uniq_quay_gtfs_stop_id', columns: ['gtfs_stop_id'])]
#[ORM\Index(name: 'idx_quay_parent_stop_id', columns: ['parent_stop_id'])]
#[ORM\Index(name: 'idx_quay_active', columns: ['active'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['quay:read']]
        ),
        new Get(
            normalizationContext: ['groups' => ['quay:read']]
        ),
    ],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'gtfsStopId' => 'exact', 'parentStopId' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class Quay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['quay:read'])]
    private ?int $id = null;

    /**
     * Identifiant naturel du quai tel que fourni par le GTFS statique
     * et référencé tel quel dans le flux GTFS-RT (ex: "0:GAR01").
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Groups(['quay:read'])]
    private string $gtfsStopId;

    /**
     * stop_name du GTFS (ex: "GARE CENTRALE").
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Groups(['quay:read'])]
    private string $name;

    #[ORM\Column(type: Types::FLOAT)]
    #[Groups(['quay:read'])]
    private float $latitude;

    #[ORM\Column(type: Types::FLOAT)]
    #[Groups(['quay:read'])]
    private float $longitude;

    /**
     * parent_station du GTFS (ex: "1:GARE"). Sert à regrouper les
     * quais d'un même arrêt physique côté sélection utilisateur.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Groups(['quay:read'])]
    private string $parentStopId;

    /**
     * Lignes desservant ce quai.
     *
     * @var Collection<int, Route>
     */
    #[ORM\ManyToMany(targetEntity: Route::class, inversedBy: 'quays')]
    #[ORM\JoinTable(name: 'quay_route')]
    #[Groups(['quay:read'])]
    private Collection $routes;

    /**
     * Destinations distinctes (trip_headsign) desservies par ce quai.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['quay:read'])]
    private ?array $destinationsServed = null;

    /**
     * false si le quai a disparu d'un import GTFS ultérieur.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['quay:read'])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['quay:read'])]
    private ?\DateTimeImmutable $deprecatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['quay:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['quay:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $gtfsStopId, string $name, float $latitude, float $longitude, string $parentStopId)
    {
        $this->gtfsStopId = $gtfsStopId;
        $this->name = $name;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->parentStopId = $parentStopId;
        $this->routes = new ArrayCollection();
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

    public function getGtfsStopId(): string
    {
        return $this->gtfsStopId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getParentStopId(): string
    {
        return $this->parentStopId;
    }

    public function setParentStopId(string $parentStopId): static
    {
        $this->parentStopId = $parentStopId;

        return $this;
    }

    /**
     * @return Collection<int, Route>
     */
    public function getRoutes(): Collection
    {
        return $this->routes;
    }

    public function addRoute(Route $route): static
    {
        if (!$this->routes->contains($route)) {
            $this->routes->add($route);
        }

        return $this;
    }

    public function removeRoute(Route $route): static
    {
        $this->routes->removeElement($route);

        return $this;
    }

    /**
     * @return string[]|null
     */
    public function getDestinationsServed(): ?array
    {
        return $this->destinationsServed;
    }

    /**
     * @param string[]|null $destinationsServed
     */
    public function setDestinationsServed(?array $destinationsServed): static
    {
        $this->destinationsServed = $destinationsServed;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function markDeprecated(): static
    {
        $this->active = false;
        $this->deprecatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markActive(): static
    {
        $this->active = true;
        $this->deprecatedAt = null;

        return $this;
    }

    public function getDeprecatedAt(): ?\DateTimeImmutable
    {
        return $this->deprecatedAt;
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
