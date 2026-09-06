<?php

namespace App\Dto\Gtfs;

/**
 * Représentation intermédiaire d'une ligne de routes.txt, sert
 * de frontière entre le parsing GTFS et la persistance Doctrine.
 */
final readonly class ParsedRoute
{
    public function __construct(
        public string $gtfsRouteId,
        public string $shortName,
        public ?string $longName,
        public string $color,
        public string $textColor,
    ) {
    }
}
