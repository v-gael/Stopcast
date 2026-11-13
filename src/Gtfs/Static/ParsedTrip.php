<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Représentation intermédiaire d'une ligne de trips.txt.
 */
final readonly class ParsedTrip
{
    public function __construct(
        public string $gtfsTripId,
        public string $gtfsRouteId,
        public ?string $headsign,
        // stop_id du dernier arrêt du trajet, null si le trajet n'a aucun horaire.
        public ?string $terminusStopId,
        // stop_sequence de ce dernier arrêt : sur une boucle, il distingue
        // l'arrivée au terminus du départ du même quai.
        public ?int $terminusStopSequence,
    ) {
    }
}
