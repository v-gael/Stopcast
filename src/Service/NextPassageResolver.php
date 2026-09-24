<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Repository\RouteRepository;
use Google\Transit\Realtime\FeedMessage;

/**
 * Résout les prochains passages pour un quai à partir d'un flux GTFS-RT déjà téléchargé.
 *
 * Volontairement pas de croisement avec les horaires théoriques (trips.txt / stop_times.txt) :
 * on fait confiance au stop_id (garanti fiable par le standard GTFS-RT) et au route_id
 * tel que fourni par le flux RT, même si celui-ci peut parfois désigner une ligne "sœur"
 * (ex: variante de service) plutôt que la ligne exacte — accepté comme compromis.
 */
final readonly class NextPassageResolver
{
    public function __construct(
        private RouteRepository $routeRepository,
        private int $limit,
    ) {
    }

    /**
     * @return NextPassage[]
     */
    public function resolveForQuay(FeedMessage $feed, Quay $quay): array
    {
        $now = new \DateTimeImmutable();
        $passages = [];

        foreach ($feed->getEntity() as $entity) {
            $tripUpdate = $entity->getTripUpdate();

            if (null === $tripUpdate) {
                continue;
            }

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                if ($stopTimeUpdate->getStopId() !== $quay->getGtfsStopId()) {
                    continue;
                }

                $event = $stopTimeUpdate->hasDeparture()
                    ? $stopTimeUpdate->getDeparture()
                    : $stopTimeUpdate->getArrival();

                $time = (int) ($event?->getTime() ?? 0);

                if (0 === $time) {
                    continue;
                }

                $expectedAt = new \DateTimeImmutable()->setTimestamp($time);

                if ($expectedAt < $now) {
                    continue;
                }

                $routeId = $tripUpdate->getTrip()?->getRouteId();
                $route = null !== $routeId ? $this->routeRepository->findOneByGtfsRouteId($routeId) : null;

                if (!$route instanceof \App\Entity\Route) {
                    continue;
                }

                $passages[] = new NextPassage(
                    routeShortName: $route->getShortName(),
                    routeColor: $route->getColor(),
                    routeTextColor: $route->getTextColor(),
                    headsign: $route->getLongName(),
                    expectedAt: $expectedAt,
                );

                // Un même trip ne dessert un quai donné qu'une fois dans sa course.
                break;
            }
        }

        usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);

        return \array_slice($passages, 0, $this->limit);
    }
}
