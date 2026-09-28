<?php

declare(strict_types=1);

namespace App\Dto\Gtfs;

/**
 * Représentation intermédiaire d'une ligne de trips.txt.
 */
final readonly class ParsedTrip
{
    public function __construct(
        public string $gtfsTripId,
        public string $gtfsRouteId,
        public ?string $headsign,
    ) {
    }
}
