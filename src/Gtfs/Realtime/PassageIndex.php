<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;

/**
 * Passages affichables de chaque quai, tirés d'un même flux temps réel et
 * croisés avec la base (PassageIndexBuilder). Les passages de chaque quai
 * sont dans l'ordre de leur heure prévue.
 */
final readonly class PassageIndex
{
    /**
     * @param int                              $feedTimestamp    header.timestamp du flux, 0 s'il est absent
     * @param array<string, list<NextPassage>> $passagesByStopId indexé par stop_id ; un quai sans passage est absent
     */
    public function __construct(
        public int $feedTimestamp,
        public array $passagesByStopId,
    ) {
    }

    /**
     * Le même index, réduit aux premiers passages de chaque quai.
     */
    public function truncated(int $passagesPerQuay): self
    {
        return new self(
            $this->feedTimestamp,
            array_map(static fn (array $passages): array => \array_slice($passages, 0, $passagesPerQuay), $this->passagesByStopId),
        );
    }
}
