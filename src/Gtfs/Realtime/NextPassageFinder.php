<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prochains passages aux quais, tirés du flux temps réel.
 *
 * Chaque appel télécharge (ou relit en cache) et parse le flux une seule fois.
 * Le flux brut est mis en cache un court instant (GTFS_REALTIME_CACHE_TTL) de
 * façon PARTAGÉE entre tous les dispositifs : peu importe combien interrogent
 * l'API en même temps, le flux amont n'est téléchargé qu'une fois par fenêtre
 * de cache. Seule une réponse qui se parse en un flux valide est mise en
 * cache : sinon, chaque appel échouerait jusqu'à l'expiration, alors que la
 * requête suivante aurait pu réussir.
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
final readonly class NextPassageFinder
{
    private const string CACHE_KEY = 'trip_updates';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'gtfs_realtime.cache')]
        private CacheItemPoolInterface $cache,
        private RouteRepository $routeRepository,
        private TripRepository $tripRepository,
        private ClockInterface $clock,
        #[Autowire(env: 'GTFS_REALTIME_TRIP_UPDATES_URL')]
        private string $tripUpdatesUrl,
        #[Autowire(env: 'int:GTFS_REALTIME_CACHE_TTL')]
        private int $cacheTtl,
        #[Autowire(env: 'int:GTFS_REALTIME_NEXT_PASSAGES_LIMIT')]
        private int $limit,
    ) {
    }

    /**
     * Prochains passages de chaque quai, dans l'ordre, dans la limite de
     * GTFS_REALTIME_NEXT_PASSAGES_LIMIT par quai.
     *
     * @param Quay[] $quays
     *
     * @return array<string, NextPassage[]> indexé par stop_id ; un quai sans passage a une liste vide
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible
     * @throws HttpClientExceptionInterface     téléchargement impossible
     */
    public function forQuays(array $quays): array
    {
        if ([] === $quays) {
            return [];
        }

        $passagesByStopId = array_fill_keys(array_map(static fn (Quay $quay): string => $quay->getGtfsStopId(), $quays), []);

        foreach ($this->displayablePassages($passagesByStopId) as $passage) {
            $passagesByStopId[$passage['stopId']][] = new NextPassage(
                routeShortName: $passage['route']->getShortName(),
                routeColor: $passage['route']->getColor(),
                routeTextColor: $passage['route']->getTextColor(),
                // Repli sur le nom de la ligne si le trajet est inconnu ou sans destination.
                headsign: $passage['headsign'] ?? $passage['route']->getLongName(),
                expectedAt: $passage['expectedAt'],
            );
        }

        return array_map(function (array $passages): array {
            usort($passages, static fn (NextPassage $a, NextPassage $b): int => $a->expectedAt <=> $b->expectedAt);

            return \array_slice($passages, 0, $this->limit);
        }, $passagesByStopId);
    }

    /**
     * Nombre de passages à venir par quai, comptés comme forQuays les
     * afficherait mais sans limite. Les quais sans passage sont absents.
     *
     * @return array<string, int> indexé par stop_id
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible
     * @throws HttpClientExceptionInterface     téléchargement impossible
     */
    public function countByStopId(): array
    {
        $counts = [];
        foreach ($this->displayablePassages(null) as $passage) {
            $counts[$passage['stopId']] = ($counts[$passage['stopId']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Passages à venir affichables, pour les quais demandés ou pour tous (null).
     *
     * @param array<string, mixed>|null $stopIds quais demandés, en clés
     *
     * @return list<array{stopId: string, route: Route, headsign: string|null, expectedAt: \DateTimeImmutable}>
     */
    private function displayablePassages(?array $stopIds): array
    {
        $candidates = $this->candidates($this->tripUpdates(), $stopIds);

        if ([] === $candidates) {
            return [];
        }

        // Une requête pour les lignes et une pour les trajets, quel que
        // soit le nombre de quais et de passages retenus.
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
     * @param array<string, mixed>|null $stopIds quais demandés, en clés ; null pour tous
     *
     * @return list<array{stopId: string, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}>
     */
    private function candidates(FeedMessage $feed, ?array $stopIds): array
    {
        $now = $this->clock->now();
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

                if ((null !== $stopIds && !\array_key_exists($stopTimeStopId, $stopIds)) || isset($settledStopIds[$stopTimeStopId])) {
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

                $expectedAt = $now->setTimestamp($time);

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

    /**
     * Le flux des mises à jour de trajets, depuis le cache partagé ou téléchargé.
     */
    private function tripUpdates(): FeedMessage
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            /** @var string $raw */
            $raw = $item->get();

            return $this->parse($raw);
        }

        $raw = $this->httpClient->request('GET', $this->tripUpdatesUrl)->getContent();
        $feed = $this->parse($raw);

        $item->set($raw);
        $item->expiresAfter($this->cacheTtl);

        $this->cache->save($item);

        return $feed;
    }

    /**
     * @throws InvalidGtfsRealtimeFeedException
     */
    private function parse(string $raw): FeedMessage
    {
        $feed = new FeedMessage();

        try {
            $feed->mergeFromString($raw);
        } catch (\Exception $exception) {
            throw InvalidGtfsRealtimeFeedException::undecodable($this->tripUpdatesUrl, $exception);
        }

        // Le header est obligatoire en GTFS-RT ; un corps vide se décode
        // sans erreur en un flux sans header.
        if (!$feed->hasHeader()) {
            throw InvalidGtfsRealtimeFeedException::missingHeader($this->tripUpdatesUrl);
        }

        return $feed;
    }
}
