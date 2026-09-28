<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedMessage;

/**
 * Résout les prochains passages pour un quai à partir d'un flux GTFS-RT déjà téléchargé.
 *
 * Volontairement pas de croisement avec les horaires théoriques (stop_times.txt) :
 * on fait confiance au stop_id (garanti fiable par le standard GTFS-RT) et au route_id
 * tel que fourni par le flux RT, même si celui-ci peut parfois désigner une ligne "sœur"
 * (ex: variante de service) plutôt que la ligne exacte — accepté comme compromis.
 *
 * Seule exception : la destination de chaque passage vient du trajet statique
 * (trips.txt), retrouvé par son trip_id, avec repli sur le nom long de la ligne
 * (voir docs/adr/0001-destination-par-trajet.md).
 */
final readonly class NextPassageResolver
{
    public function __construct(
        private RouteRepository $routeRepository,
        private TripRepository $tripRepository,
        private int $limit,
    ) {
    }

    /**
     * @return NextPassage[]
     */
    public function resolveForQuay(FeedMessage $feed, Quay $quay): array
    {
        $now = new \DateTimeImmutable();
        $candidates = [];

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

                $routeId = $tripUpdate->getTrip()?->getRouteId() ?? '';

                if ('' === $routeId) {
                    continue;
                }

                $candidates[] = [
                    'routeId' => $routeId,
                    'tripId' => $tripUpdate->getTrip()?->getTripId() ?? '',
                    'expectedAt' => $expectedAt,
                ];

                // Un même trip ne dessert un quai donné qu'une fois dans sa course.
                break;
            }
        }

        if ([] === $candidates) {
            return [];
        }

        // Une requête pour les lignes et une pour les destinations, quel que
        // soit le nombre de passages retenus.
        $routesByGtfsRouteId = [];
        foreach ($this->routeRepository->findBy(['gtfsRouteId' => array_values(array_unique(array_column($candidates, 'routeId')))]) as $route) {
            $routesByGtfsRouteId[$route->getGtfsRouteId()] = $route;
        }

        $headsignsByTripId = $this->tripRepository->findHeadsignsByGtfsTripIds(
            array_filter(array_column($candidates, 'tripId'), static fn (string $tripId): bool => '' !== $tripId),
        );

        $passages = [];
        foreach ($candidates as $candidate) {
            $route = $routesByGtfsRouteId[$candidate['routeId']] ?? null;

            if (null === $route) {
                continue;
            }

            $passages[] = new NextPassage(
                routeShortName: $route->getShortName(),
                routeColor: $route->getColor(),
                routeTextColor: $route->getTextColor(),
                // Repli sur le nom de la ligne si le trajet est inconnu ou sans destination.
                headsign: $headsignsByTripId[$candidate['tripId']] ?? $route->getLongName(),
                expectedAt: $candidate['expectedAt'],
            );
        }

        usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);

        return \array_slice($passages, 0, $this->limit);
    }
}
