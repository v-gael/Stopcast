<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TripRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trajet du GTFS statique (trips.txt), conservé uniquement pour sa
 * destination et son terminus : voir docs/adr/0001-destination-par-trajet.md
 * et docs/adr/0002-terminus-par-trajet.md.
 * Remplacé intégralement à chaque import, jamais exposé par l'API.
 */
#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: 'trip')]
#[ORM\UniqueConstraint(name: 'uniq_trip_gtfs_trip_id', columns: ['gtfs_trip_id'])]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * trip_id du GTFS, référencé tel quel dans le flux GTFS-RT.
     */
    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $gtfsTripId;

    #[ORM\ManyToOne(targetEntity: Route::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Route $route;

    /**
     * trip_headsign : la destination affichée pour ce trajet.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $headsign;

    /**
     * stop_id GTFS du dernier arrêt du trajet (stop_times.txt). Pas de relation
     * vers Quay : cet arrêt n'est pas forcément importé comme quai.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $terminusStopId;

    public function __construct(string $gtfsTripId, Route $route, ?string $headsign, ?string $terminusStopId = null)
    {
        $this->gtfsTripId = $gtfsTripId;
        $this->route = $route;
        $this->headsign = $headsign;
        $this->terminusStopId = $terminusStopId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGtfsTripId(): string
    {
        return $this->gtfsTripId;
    }

    public function getRoute(): Route
    {
        return $this->route;
    }

    public function getHeadsign(): ?string
    {
        return $this->headsign;
    }

    public function getTerminusStopId(): ?string
    {
        return $this->terminusStopId;
    }
}
