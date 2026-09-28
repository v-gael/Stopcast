<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
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
        $passages = [];
        foreach ($this->displayablePassages($feed, $quay->getGtfsStopId()) as $passage) {
            $passages[] = new NextPassage(
                routeShortName: $passage['route']->getShortName(),
                routeColor: $passage['route']->getColor(),
                routeTextColor: $passage['route']->getTextColor(),
                // Repli sur le nom de la ligne si le trajet est inconnu ou sans destination.
                headsign: $passage['headsign'] ?? $passage['route']->getLongName(),
                expectedAt: $passage['expectedAt'],
            );
        }

        usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);

        return \array_slice($passages, 0, $this->limit);
    }

    /**
     * Nombre de passages à venir par quai, comptés comme resolveForQuay les
     * afficherait mais sans limite. Les quais sans passage sont absents.
     *
     * @return array<string, int> indexé par stop_id
     */
    public function countByStopId(FeedMessage $feed): array
    {
        $counts = [];
        foreach ($this->displayablePassages($feed, null) as $passage) {
            $counts[$passage['stopId']] = ($counts[$passage['stopId']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Passages à venir affichables, pour un quai ou pour tous (null).
     *
     * @return list<array{stopId: string, route: Route, headsign: string|null, expectedAt: \DateTimeImmutable}>
     */
    private function displayablePassages(FeedMessage $feed, ?string $stopId): array
    {
        $candidates = $this->candidates($feed, $stopId);

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
            if ($candidate['stopId'] === ($trip['terminusStopId'] ?? null)) {
                continue;
            }

            $passages[] = [
                'stopId' => $candidate['stopId'],
                'route' => $route,
                'headsign' => $trip['headsign'] ?? null,
                'expectedAt' => $candidate['expectedAt'],
            ];
        }

        return $passages;
    }

    /**
     * Passages à venir lus dans le flux, avant tout croisement avec la base.
     *
     * @return list<array{stopId: string, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}>
     */
    private function candidates(FeedMessage $feed, ?string $stopId): array
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

            $routeId = $tripUpdate->getTrip()?->getRouteId() ?? '';

            if ('' === $routeId) {
                continue;
            }

            $tripId = $tripUpdate->getTrip()?->getTripId() ?? '';

            // Un même trip ne dessert un quai donné qu'une fois dans sa course :
            // un quai retenu ou sauté n'est plus examiné pour ce trip.
            $settledStopIds = [];

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                $stopTimeStopId = $stopTimeUpdate->getStopId();

                if ((null !== $stopId && $stopTimeStopId !== $stopId) || isset($settledStopIds[$stopTimeStopId])) {
                    continue;
                }

                // Arrêt sauté : le bus passe sans s'arrêter au quai.
                if (StopScheduleRelationship::SKIPPED === $stopTimeUpdate->getScheduleRelationship()) {
                    $settledStopIds[$stopTimeStopId] = true;

                    continue;
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

                $candidates[] = [
                    'stopId' => $stopTimeStopId,
                    'routeId' => $routeId,
                    'tripId' => $tripId,
                    'expectedAt' => $expectedAt,
                ];
                $settledStopIds[$stopTimeStopId] = true;
            }
        }

        return $candidates;
    }

    private function timeOf(?StopTimeEvent $event): int
    {
        return (int) ($event?->getTime() ?? 0);
    }
}
