<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire(env: 'int:GTFS_REALTIME_NEXT_PASSAGES_LIMIT')]
        private int $limit,
    ) {
    }

    /**
     * Prochains passages de chaque quai, dans l'ordre, sans ceux déjà partis,
     * dans la limite de GTFS_REALTIME_NEXT_PASSAGES_LIMIT par quai.
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
        $this->tripUpdatesFeed->assertFresh($index->feedTimestamp);

        $now = $this->clock->now();
        $passagesByStopId = [];

        foreach ($quays as $quay) {
            $stopId = $quay->getGtfsStopId();
            $upcoming = array_filter($index->passagesByStopId[$stopId] ?? [], static fn (NextPassage $passage): bool => $passage->expectedAt >= $now);
            $passagesByStopId[$stopId] = \array_slice(array_values($upcoming), 0, $this->limit);
        }

        return $passagesByStopId;
    }
}
