<?php

namespace App\Dto\Gtfs;

/**
 * Représentation intermédiaire d'un quai (stops.txt, location_type=0)
 */
final readonly class ParsedQuay
{
    public function __construct(
        public string $gtfsStopId,
        public string $name,
        public float $latitude,
        public float $longitude,
        public string $parentStopId,
    ) {
    }
}
