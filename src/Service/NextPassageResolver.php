<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;

/**
 * Résout les prochains passages pour un quai à partir d'un flux GTFS-RT déjà téléchargé.
 *
 * Volontairement pas de croisement avec les horaires théoriques (stop_times.txt) :
 * on fait confiance au stop_id (garanti fiable par le standard GTFS-RT) et au route_id
 * tel que fourni par le flux RT, même si celui-ci peut parfois désigner une ligne "sœur"
 * (ex: variante de service) plutôt que la ligne exacte — accepté comme compromis.
 *
 * Seule exception : le trajet statique, retrouvé par son trip_id, donne la
 * destination de chaque passage, avec repli sur le nom long de la ligne
 * (voir docs/adr/0001-destination-par-trajet.md), et son terminus : un passage
 * au quai où son trajet se termine n'est pas affiché, personne ne pouvant y
 * monter (voir docs/adr/0002-terminus-par-trajet.md).
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

            // Trajet supprimé du service : aucun de ses passages n'est à afficher.
            if (\in_array($tripUpdate->getTrip()?->getScheduleRelationship(), [TripScheduleRelationship::CANCELED, TripScheduleRelationship::DELETED], true)) {
                continue;
            }

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                if ($stopTimeUpdate->getStopId() !== $quay->getGtfsStopId()) {
                    continue;
                }

                // Arrêt sauté : le bus passe sans s'arrêter au quai.
                if (StopScheduleRelationship::SKIPPED === $stopTimeUpdate->getScheduleRelationship()) {
                    break;
                }

                // Le départ peut ne porter qu'un delay (heure à 0) : on se rabat
                // alors sur l'arrivée.
                $time = $this->timeOf($stopTimeUpdate->getDeparture()) ?: $this->timeOf($stopTimeUpdate->getArrival());

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

        // Une requête pour les lignes et une pour les trajets, quel que
        // soit le nombre de passages retenus.
        $routesByGtfsRouteId = [];
        foreach ($this->routeRepository->findBy(['gtfsRouteId' => array_values(array_unique(array_column($candidates, 'routeId')))]) as $route) {
            $routesByGtfsRouteId[$route->getGtfsRouteId()] = $route;
        }

        $tripsByTripId = $this->tripRepository->findHeadsignsAndTerminiByGtfsTripIds(
            array_filter(array_column($candidates, 'tripId'), static fn (string $tripId): bool => '' !== $tripId),
        );

        $passages = [];
        foreach ($candidates as $candidate) {
            $route = $routesByGtfsRouteId[$candidate['routeId']] ?? null;

            if (null === $route) {
                continue;
            }

            $trip = $tripsByTripId[$candidate['tripId']] ?? null;

            // Filtré avant la limite, pour ne pas prendre la place d'un passage
            // affichable. Trajet ou terminus inconnu : le passage reste affiché.
            if ($quay->getGtfsStopId() === ($trip['terminusStopId'] ?? null)) {
                continue;
            }

            $passages[] = new NextPassage(
                routeShortName: $route->getShortName(),
                routeColor: $route->getColor(),
                routeTextColor: $route->getTextColor(),
                // Repli sur le nom de la ligne si le trajet est inconnu ou sans destination.
                headsign: $trip['headsign'] ?? $route->getLongName(),
                expectedAt: $candidate['expectedAt'],
            );
        }

        usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);

        return \array_slice($passages, 0, $this->limit);
    }

    private function timeOf(?StopTimeEvent $event): int
    {
        return (int) ($event?->getTime() ?? 0);
    }
}
