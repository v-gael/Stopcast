<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Prochains passages aux quais, tirés du flux temps réel.
 *
 * Chaque appel télécharge (ou relit en cache) et parse le flux une seule fois
 * (TripUpdatesFeed). Le flux brut est mis en cache un court instant
 * (GTFS_REALTIME_CACHE_TTL) de façon PARTAGÉE entre tous les écrans : peu
 * importe combien rafraîchissent en même temps, le flux amont n'est
 * téléchargé qu'une fois par fenêtre de cache. Seule une réponse qui se
 * décode est mise en cache : sinon, chaque appel échouerait jusqu'à
 * l'expiration, alors que la requête suivante aurait pu réussir. Un flux
 * périmé, lisible, est gardé comme les autres ; son âge est revérifié à
 * chaque relecture.
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
        private TripUpdatesFeed $tripUpdatesFeed,
        #[Autowire(service: 'gtfs_realtime.cache')]
        private CacheItemPoolInterface $cache,
        private RouteRepository $routeRepository,
        private TripRepository $tripRepository,
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
     * @throws InvalidGtfsRealtimeFeedException flux illisible ou périmé
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
     * @throws InvalidGtfsRealtimeFeedException flux illisible ou périmé
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
        $candidates = $this->tripUpdatesFeed->upcomingStopTimes($this->tripUpdates(), $stopIds);

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
     * Le flux des mises à jour de trajets, depuis le cache partagé ou téléchargé.
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible ou périmé
     * @throws HttpClientExceptionInterface     téléchargement impossible
     */
    private function tripUpdates(): FeedMessage
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            /** @var string $raw */
            $raw = $item->get();

            return $this->tripUpdatesFeed->parse($raw);
        }

        $raw = $this->tripUpdatesFeed->downloadRaw();
        $feed = $this->tripUpdatesFeed->decode($raw);

        // Mis en cache dès qu'il se décode, même périmé : un flux figé n'est
        // pas retéléchargé à chaque requête, et la relecture revérifie son âge.
        $item->set($raw);
        $item->expiresAfter($this->cacheTtl);

        $this->cache->save($item);

        $this->tripUpdatesFeed->assertFresh($feed);

        return $feed;
    }
}
