<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Lien entre un quai et une ligne, déduit du croisement trips.txt /
 * stop_times.txt : une ligne dessert un quai avec une ou plusieurs
 * destinations réelles distinctes (trip_headsign) en cas de branches.
 */
final readonly class ParsedQuayRouteLink
{
    /**
     * @param string[] $destinations
     */
    public function __construct(
        public string $gtfsStopId,
        public string $gtfsRouteId,
        public array $destinations,
    ) {
    }
}
