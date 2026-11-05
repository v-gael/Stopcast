<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Importe le référentiel statique : télécharge l'archive GTFS, la lit, puis
 * synchronise lignes, quais et trajets dans une transaction unique.
 *
 * Lignes et quais sont mis à jour sur leurs identifiants GTFS et désactivés
 * (jamais supprimés) quand ils disparaissent, pour préserver les
 * configurations d'affichage. Les trajets, que rien ne référence, sont
 * remplacés intégralement.
 *
 * L'import conditionnel envoie l'ETag et le Last-Modified du dernier import
 * réussi de la même URL, et s'arrête sur un 304 : le fichier n'a pas changé.
 */
final readonly class StaticReferenceImporter
{
    private const int TRIP_BATCH_SIZE = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private StaticFeedReader $reader,
        #[Autowire(service: 'gtfs_static.cache')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'GTFS_STATIC_FEED_URL')]
        private string $feedUrl,
    ) {
    }

    /**
     * @param bool $onlyIfChanged sauter l'import si le serveur répond que le fichier n'a pas changé
     *
     * @return GtfsImportSummary|null null quand l'import est sauté
     *
     * @throws InvalidGtfsFeedException     archive illisible ou incomplète
     * @throws HttpClientExceptionInterface téléchargement impossible, ou réponse 4xx/5xx
     */
    public function import(bool $onlyIfChanged = false): ?GtfsImportSummary
    {
        $response = $this->httpClient->request('GET', $this->feedUrl, [
            'headers' => $onlyIfChanged ? $this->conditionalHeaders() : [],
        ]);

        if (304 === $response->getStatusCode()) {
            return null;
        }

        $feed = $this->read($response);

        $this->entityManager->beginTransaction();

        try {
            $routeSummary = $this->upsertRoutes($feed->routes);
            $quaySummary = $this->upsertQuays($feed->quays);

            $this->entityManager->flush();

            $this->linkQuaysToRoutes($feed->quayRouteLinks);

            $deactivationSummary = $this->deactivateMissing(
                seenGtfsRouteIds: array_map(static fn (ParsedRoute $route): string => $route->gtfsRouteId, $feed->routes),
                seenGtfsStopIds: array_map(static fn (ParsedQuay $quay): string => $quay->gtfsStopId, $feed->quays),
            );

            $this->entityManager->flush();

            // En dernier : vide l'unit of work par lots, tout le reste doit déjà être flushé.
            $this->replaceTrips($feed->trips);

            $this->entityManager->commit();
        } catch (\Throwable $throwable) {
            $this->entityManager->rollback();
            throw $throwable;
        }

        // Après le commit : un import raté ne fait pas passer le fichier pour importé.
        $this->rememberValidators($response);

        return $routeSummary
            ->merge($quaySummary)
            ->merge($deactivationSummary);
    }

    /**
     * @return array<string, string>
     */
    private function conditionalHeaders(): array
    {
        $item = $this->cache->getItem($this->validatorsKey());
        if (!$item->isHit()) {
            return [];
        }

        /** @var array{etag?: string, last_modified?: string} $validators */
        $validators = $item->get();

        return array_filter([
            'If-None-Match' => $validators['etag'] ?? null,
            'If-Modified-Since' => $validators['last_modified'] ?? null,
        ], static fn (?string $value): bool => null !== $value);
    }

    private function rememberValidators(ResponseInterface $response): void
    {
        $headers = $response->getHeaders(false);
        $validators = array_filter([
            'etag' => $headers['etag'][0] ?? null,
            'last_modified' => $headers['last-modified'][0] ?? null,
        ], static fn (?string $value): bool => null !== $value);

        if ([] === $validators) {
            $this->cache->deleteItem($this->validatorsKey());
            $this->logger->info('GTFS import: the feed server sends neither ETag nor Last-Modified, every conditional import will download the feed.');

            return;
        }

        $this->cache->save($this->cache->getItem($this->validatorsKey())->set($validators));
    }

    /**
     * Par URL : changer de flux ne réutilise pas les validateurs de l'ancien.
     */
    private function validatorsKey(): string
    {
        return 'validators.'.hash('xxh128', $this->feedUrl);
    }

    /**
     * Écrit l'archive dans un fichier temporaire, le temps de la lire.
     */
    private function read(ResponseInterface $response): StaticFeed
    {
        // Contenu récupéré avant de créer le fichier temporaire, pour ne rien
        // laisser derrière soi si le téléchargement échoue.
        $content = $response->getContent();

        $archivePath = $this->filesystem->tempnam(sys_get_temp_dir(), 'gtfs_', '.zip');

        try {
            $this->filesystem->dumpFile($archivePath, $content);

            return $this->reader->read($archivePath);
        } finally {
            $this->filesystem->remove($archivePath);
        }
    }

    /**
     * @param list<ParsedRoute> $parsedRoutes
     */
    private function upsertRoutes(array $parsedRoutes): GtfsImportSummary
    {
        $summary = new GtfsImportSummary();
        $repository = $this->entityManager->getRepository(Route::class);

        foreach ($parsedRoutes as $parsedRoute) {
            $route = $repository->findOneBy(['gtfsRouteId' => $parsedRoute->gtfsRouteId]);

            if (null === $route) {
                $route = new Route(
                    $parsedRoute->gtfsRouteId,
                    $parsedRoute->shortName,
                    $parsedRoute->color,
                    $parsedRoute->textColor,
                );
                $this->entityManager->persist($route);
                ++$summary->created;
            } else {
                $route
                    ->setShortName($parsedRoute->shortName)
                    ->setColor($parsedRoute->color)
                    ->setTextColor($parsedRoute->textColor)
                    ->markActive();
                ++$summary->updated;
            }

            $route->setLongName($parsedRoute->longName);
        }

        return $summary;
    }

    /**
     * @param list<ParsedQuay> $parsedQuays
     */
    private function upsertQuays(array $parsedQuays): GtfsImportSummary
    {
        $summary = new GtfsImportSummary();
        $repository = $this->entityManager->getRepository(Quay::class);

        foreach ($parsedQuays as $parsedQuay) {
            $quay = $repository->findOneBy(['gtfsStopId' => $parsedQuay->gtfsStopId]);

            if (null === $quay) {
                $quay = new Quay(
                    $parsedQuay->gtfsStopId,
                    $parsedQuay->name,
                    $parsedQuay->latitude,
                    $parsedQuay->longitude,
                    $parsedQuay->parentStopId,
                );
                $this->entityManager->persist($quay);
                ++$summary->created;
            } else {
                $quay
                    ->setName($parsedQuay->name)
                    ->setLatitude($parsedQuay->latitude)
                    ->setLongitude($parsedQuay->longitude)
                    ->setParentStopId($parsedQuay->parentStopId)
                    ->markActive();
                ++$summary->updated;
            }
        }

        return $summary;
    }

    /**
     * Associe les Route aux Quay (relation ManyToMany) et met à jour
     * Quay::$destinationsServed. Les lignes et quais doivent déjà être flushés.
     *
     * @param list<ParsedQuayRouteLink> $parsedQuayRouteLinks
     */
    private function linkQuaysToRoutes(array $parsedQuayRouteLinks): void
    {
        $quayRepository = $this->entityManager->getRepository(Quay::class);
        $routeRepository = $this->entityManager->getRepository(Route::class);

        // Regroupe les destinations par quai avant assignation, un quai
        // pouvant apparaître dans plusieurs liens (un par ligne desservie).
        $destinationsByStopId = [];
        $routeIdsByStopId = [];
        foreach ($parsedQuayRouteLinks as $link) {
            $destinationsByStopId[$link->gtfsStopId] ??= [];
            foreach ($link->destinations as $destination) {
                $destinationsByStopId[$link->gtfsStopId][$destination] = true;
            }

            $routeIdsByStopId[$link->gtfsStopId][$link->gtfsRouteId] = true;
        }

        // Retire les liens et destinations que le flux courant ne contient
        // plus (ligne qui ne dessert plus un quai, quai plus desservi).
        foreach ($quayRepository->findAll() as $quay) {
            $expectedRouteIds = $routeIdsByStopId[$quay->getGtfsStopId()] ?? [];

            foreach ($quay->getRoutes()->toArray() as $route) {
                if (!isset($expectedRouteIds[$route->getGtfsRouteId()])) {
                    $quay->removeRoute($route);
                }
            }

            if (!isset($destinationsByStopId[$quay->getGtfsStopId()])) {
                $quay->setDestinationsServed(null);
            }
        }

        foreach ($parsedQuayRouteLinks as $link) {
            $quay = $quayRepository->findOneBy(['gtfsStopId' => $link->gtfsStopId]);
            $route = $routeRepository->findOneBy(['gtfsRouteId' => $link->gtfsRouteId]);

            if (null === $quay || null === $route) {
                // Référence orpheline (trip vers un stop_id absent de
                // stops.txt, ou route_id absent de routes.txt) : on
                // l'ignore plutôt que de faire échouer tout l'import.
                continue;
            }

            $quay->addRoute($route);
        }

        foreach ($destinationsByStopId as $gtfsStopId => $destinations) {
            $quay = $quayRepository->findOneBy(['gtfsStopId' => $gtfsStopId]);
            // Quai desservi uniquement par des trajets sans destination : aucune à proposer.
            $quay?->setDestinationsServed([] !== $destinations ? array_map(strval(...), array_keys($destinations)) : null);
        }
    }

    /**
     * Désactive (sans supprimer) les Route/Quay absents du dernier
     * import, pour préserver les DisplayConfiguration existantes.
     *
     * @param string[] $seenGtfsRouteIds
     * @param string[] $seenGtfsStopIds
     */
    private function deactivateMissing(array $seenGtfsRouteIds, array $seenGtfsStopIds): GtfsImportSummary
    {
        $summary = new GtfsImportSummary();

        /** @var Route[] $missingRoutes */
        $missingRoutes = $this->entityManager->getRepository(Route::class)
            ->createQueryBuilder('r')
            ->where('r.deprecatedAt IS NULL')
            ->andWhere('r.gtfsRouteId NOT IN (:seenIds)')
            ->setParameter('seenIds', $seenGtfsRouteIds)
            ->getQuery()
            ->getResult();

        foreach ($missingRoutes as $route) {
            $route->markDeprecated();
            ++$summary->deactivated;
        }

        /** @var Quay[] $missingQuays */
        $missingQuays = $this->entityManager->getRepository(Quay::class)
            ->createQueryBuilder('q')
            ->where('q.deprecatedAt IS NULL')
            ->andWhere('q.gtfsStopId NOT IN (:seenIds)')
            ->setParameter('seenIds', $seenGtfsStopIds)
            ->getQuery()
            ->getResult();

        foreach ($missingQuays as $quay) {
            $quay->markDeprecated();
            ++$summary->deactivated;
        }

        return $summary;
    }

    /**
     * Remplace tous les Trip par ceux du flux courant. Insertion par lots
     * (trips.txt peut compter des dizaines de milliers de lignes), avec des
     * références vers les Route pour survivre au clear().
     *
     * @param list<ParsedTrip> $parsedTrips
     */
    private function replaceTrips(array $parsedTrips): void
    {
        $this->entityManager->createQuery('DELETE FROM '.Trip::class)->execute();

        /** @var list<array{id: int, gtfsRouteId: string}> $routeRows */
        $routeRows = $this->entityManager->getRepository(Route::class)
            ->createQueryBuilder('r')
            ->select('r.id', 'r.gtfsRouteId')
            ->getQuery()
            ->getArrayResult();
        $routeIdByGtfsRouteId = array_column($routeRows, 'id', 'gtfsRouteId');

        $pending = 0;
        foreach ($parsedTrips as $parsedTrip) {
            $routeId = $routeIdByGtfsRouteId[$parsedTrip->gtfsRouteId] ?? null;

            if (null === $routeId) {
                // Trajet vers un route_id absent de routes.txt : ignoré.
                continue;
            }

            $route = $this->entityManager->getReference(Route::class, $routeId);
            \assert($route instanceof Route);

            $this->entityManager->persist(new Trip($parsedTrip->gtfsTripId, $route, $parsedTrip->headsign, $parsedTrip->terminusStopId));

            if (0 === ++$pending % self::TRIP_BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
