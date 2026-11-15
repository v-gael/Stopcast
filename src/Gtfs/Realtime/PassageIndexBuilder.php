<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Construit l'index des passages de tous les quais : télécharge le flux temps
 * réel, le décode, et le croise avec la base, en une requête pour les lignes
 * et une pour les trajets, quel que soit le nombre de quais et de passages.
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
final readonly class PassageIndexBuilder
{
    public function __construct(
        private TripUpdatesFeed $tripUpdatesFeed,
        private RouteRepository $routeRepository,
        private TripRepository $tripRepository,
    ) {
    }

    /**
     * Tous les passages à venir affichables, sans limite par quai. L'âge du
     * flux n'est pas vérifié : l'index en garde le timestamp.
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible
     * @throws HttpClientExceptionInterface     téléchargement impossible
     */
    public function build(): PassageIndex
    {
        $feed = $this->tripUpdatesFeed->decode($this->tripUpdatesFeed->downloadRaw());
        $candidates = $this->tripUpdatesFeed->upcomingStopTimes($feed);

        $routesByGtfsRouteId = [];
        $tripsByTripId = [];

        if ([] !== $candidates) {
            foreach ($this->routeRepository->findBy(['gtfsRouteId' => array_values(array_unique(array_column($candidates, 'routeId')))]) as $route) {
                $routesByGtfsRouteId[$route->getGtfsRouteId()] = $route;
            }

            $tripsByTripId = $this->tripRepository->findHeadsignsAndTerminiByGtfsTripIds(
                array_filter(array_column($candidates, 'tripId'), static fn (string $tripId): bool => '' !== $tripId),
            );
        }

        $passagesByStopId = [];
        foreach ($candidates as $candidate) {
            $route = $routesByGtfsRouteId[$candidate['routeId']] ?? null;

            if (null === $route) {
                continue;
            }

            $trip = $tripsByTripId[$candidate['tripId']] ?? null;

            // Trajet ou terminus inconnu : le passage reste affiché.
            if (null !== $trip && $this->endsItsTrip($candidate, $trip)) {
                continue;
            }

            $passagesByStopId[$candidate['stopId']][] = new NextPassage(
                routeShortName: $route->getShortName(),
                routeColor: $route->getColor(),
                routeTextColor: $route->getTextColor(),
                // Repli sur le nom de la ligne si le trajet est inconnu ou sans destination.
                headsign: $trip['headsign'] ?? $route->getLongName(),
                expectedAt: $candidate['expectedAt'],
            );
        }

        foreach ($passagesByStopId as &$passages) {
            usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);
        }

        unset($passages);

        return new PassageIndex(TripUpdatesFeed::timestampOf($feed), $passagesByStopId);
    }

    /**
     * Le passage est-il l'arrivée de son trajet au terminus ? Sur une boucle,
     * le quai de départ est aussi le terminus : seule la séquence distingue
     * le départ, affiché, de l'arrivée. Sans séquence, dans le flux ou en
     * base, tout passage au quai terminus est masqué.
     *
     * @param array{stopId: string, stopSequence: int|null}                      $candidate
     * @param array{terminusStopId: string|null, terminusStopSequence: int|null} $trip
     */
    private function endsItsTrip(array $candidate, array $trip): bool
    {
        if ($candidate['stopId'] !== $trip['terminusStopId']) {
            return false;
        }

        if (null === $candidate['stopSequence'] || null === $trip['terminusStopSequence']) {
            return true;
        }

        return $candidate['stopSequence'] === $trip['terminusStopSequence'];
    }
}
