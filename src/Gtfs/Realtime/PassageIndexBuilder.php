<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Monitoring\RealtimeFeedMismatchLogger;
use App\Repository\QuayRepository;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Construit l'index des passages de tous les quais : télécharge le flux temps
 * réel, le décode, et le croise avec la base, en une requête pour les quais,
 * une pour les lignes et une pour les trajets (deux si des courses sont écrites
 * sans leur service), quel que soit le nombre de quais et de passages.
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
    private const string QUAY_PREFIX = '0:';

    public function __construct(
        private TripUpdatesFeed $tripUpdatesFeed,
        private QuayRepository $quayRepository,
        private RouteRepository $routeRepository,
        private TripRepository $tripRepository,
        private RealtimeFeedMismatchLogger $mismatchLogger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Tous les passages à venir affichables, sans limite par quai. L'âge du
     * flux n'est pas vérifié : l'index en garde le timestamp, et l'heure de
     * sa construction pour un flux qui n'en a pas.
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible
     * @throws HttpClientExceptionInterface     téléchargement impossible
     */
    public function build(): PassageIndex
    {
        $feed = $this->tripUpdatesFeed->decode($this->tripUpdatesFeed->downloadRaw());
        $candidates = $this->withKnownStopIds($this->tripUpdatesFeed->upcomingStopTimes($feed));

        $routesByGtfsRouteId = [];
        $tripsByTripId = [];

        if ([] !== $candidates) {
            foreach ($this->routeRepository->findBy(['gtfsRouteId' => array_values(array_unique(array_column($candidates, 'routeId')))]) as $route) {
                $routesByGtfsRouteId[$route->getGtfsRouteId()] = $route;
            }

            $tripIds = array_values(array_unique(array_filter(array_column($candidates, 'tripId'), static fn (string $tripId): bool => '' !== $tripId)));
            $tripsByTripId = $this->tripRepository->findHeadsignsAndTerminiByGtfsTripIds($tripIds);
            // Course écrite sans son service (`C2026456`) : retrouvée par la fin du trip_id.
            $tripsByTripId += $this->tripRepository->findHeadsignsAndTerminiByCourseIds(
                array_values(array_filter($tripIds, static fn (string $tripId): bool => !isset($tripsByTripId[$tripId]))),
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

        $feedTimestamp = TripUpdatesFeed::timestampOf($feed);

        return new PassageIndex(0 === $feedTimestamp ? null : $feedTimestamp, $this->clock->now()->getTimestamp(), $passagesByStopId);
    }

    /**
     * Les passages, rattachés aux quais de la base. Le stop_id du flux est
     * gardé s'il est connu tel quel ; sinon, s'il l'est précédé de
     * QUAY_PREFIX, c'est ce quai. Depuis le 2026-11-20, le flux de TCAT écrit
     * `PAt01` pour le quai `0:PAt01` de son référentiel, mais garde `30000`
     * pour ses quais sans préfixe. La casse compte : `0:BRO02` et `0:BRo02`
     * sont deux quais. Des passages dont aucun ne tombe sur un quai connu
     * sont signalés (RealtimeFeedMismatchLogger) : les écrans resteraient
     * vides sans que le flux soit en panne.
     *
     * @param list<array{stopId: string, stopSequence: int|null, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}> $candidates
     *
     * @return list<array{stopId: string, stopSequence: int|null, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}>
     */
    private function withKnownStopIds(array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        $feedStopIds = array_values(array_unique(array_column($candidates, 'stopId')));
        $knownStopIds = $this->quayRepository->findKnownGtfsStopIds([
            ...$feedStopIds,
            ...array_map(static fn (string $stopId): string => self::QUAY_PREFIX.$stopId, $feedStopIds),
        ]);

        $onKnownQuay = false;
        foreach ($candidates as &$candidate) {
            if (!isset($knownStopIds[$candidate['stopId']]) && isset($knownStopIds[self::QUAY_PREFIX.$candidate['stopId']])) {
                $candidate['stopId'] = self::QUAY_PREFIX.$candidate['stopId'];
            }

            $onKnownQuay = $onKnownQuay || isset($knownStopIds[$candidate['stopId']]);
        }

        unset($candidate);

        if (!$onKnownQuay) {
            $this->mismatchLogger->log(\count($candidates), \array_slice($feedStopIds, 0, 5));
        }

        return $candidates;
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
