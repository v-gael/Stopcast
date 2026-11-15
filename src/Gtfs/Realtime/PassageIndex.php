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
     * @param int|null                         $feedTimestamp    header.timestamp du flux, null s'il est absent
     * @param int                              $builtAt          heure de la construction (timestamp Unix)
     * @param array<string, list<NextPassage>> $passagesByStopId indexé par stop_id ; un quai sans passage est absent
     */
    public function __construct(
        public ?int $feedTimestamp,
        public int $builtAt,
        public array $passagesByStopId,
    ) {
    }

    /**
     * L'heure dont l'âge de l'index se mesure : celle du flux, ou à défaut
     * celle de la construction, pour qu'un index sans timestamp périme aussi
     * quand realtime-worker ne le rafraîchit plus.
     */
    public function publishedAt(): int
    {
        return $this->feedTimestamp ?? $this->builtAt;
    }

    /**
     * Le même index, réduit aux premiers passages de chaque quai.
     */
    public function truncated(int $passagesPerQuay): self
    {
        return new self(
            $this->feedTimestamp,
            $this->builtAt,
            array_map(static fn (array $passages): array => \array_slice($passages, 0, $passagesPerQuay), $this->passagesByStopId),
        );
    }
}
