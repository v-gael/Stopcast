<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Display\DisplayPage;
use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use Psr\Clock\ClockInterface;

/**
 * Prochains passages aux quais, lus dans l'index que realtime-worker
 * rafraîchit (PassageIndexRefresher) : ni téléchargement, ni décodage, ni
 * requête SQL, quel que soit le nombre d'écrans qui lisent en même temps. Un
 * index absent n'est pas recalculé ici : l'écran affiche « flux indisponible ».
 */
final readonly class NextPassageFinder
{
    public function __construct(
        private PassageIndexStore $store,
        private TripUpdatesFeed $tripUpdatesFeed,
        private ClockInterface $clock,
        /** Passages lus par quai : ceux d'un écran (DisplayPage::PASSAGES_PER_SCREEN). */
        private int $limit = DisplayPage::PASSAGES_PER_SCREEN,
    ) {
    }

    /**
     * Prochains passages de chaque quai, dans l'ordre, sans ceux tenus pour partis
     * (NextPassage::isStillExpectedAt()),
     * dans la limite de $limit par quai.
     *
     * @param Quay[] $quays
     *
     * @return array<string, NextPassage[]> indexé par stop_id ; un quai sans passage a une liste vide
     *
     * @throws InvalidGtfsRealtimeFeedException index absent, ou flux périmé
     */
    public function forQuays(array $quays): array
    {
        if ([] === $quays) {
            return [];
        }

        $index = $this->store->load() ?? throw InvalidGtfsRealtimeFeedException::notIndexed();
        $this->tripUpdatesFeed->assertIndexFresh($index);

        $now = $this->clock->now();
        $passagesByStopId = [];

        foreach ($quays as $quay) {
            $stopId = $quay->getGtfsStopId();
            $upcoming = array_filter($index->passagesByStopId[$stopId] ?? [], static fn (NextPassage $passage): bool => $passage->isStillExpectedAt($now));
            $passagesByStopId[$stopId] = \array_slice(array_values($upcoming), 0, $this->limit);
        }

        return $passagesByStopId;
    }
}
