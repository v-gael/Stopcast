<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Gtfs\GtfsImportSummary;
use App\Dto\Gtfs\ParsedQuay;
use App\Dto\Gtfs\ParsedQuayRouteLink;
use App\Dto\Gtfs\ParsedRoute;
use App\Entity\Quay;
use App\Entity\Route;
use App\Exception\InvalidGtfsFeedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GtfsService
{
    private const array REQUIRED_FILES = ['routes.txt', 'stops.txt', 'trips.txt', 'stop_times.txt'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly Filesystem $filesystem,
        private readonly string $gtfsStaticFeedUrl,
    ) {
    }

    /**
     * Orchestration complète : télécharge, valide, parse et persiste le GTFS statique dans une transaction unique.
     *
     * @throws \Throwable
     */
    public function importStaticFeed(): GtfsImportSummary
    {
        $extractPath = $this->downloadAndExtractFeed();

        try {
            $this->validateFeedContents($extractPath);

            $parsedRoutes = $this->parseRoutes($extractPath);
            $parsedQuays = $this->parseQuays($extractPath);
            $parsedLinks = $this->parseQuayRouteLinks($extractPath);

            $this->entityManager->beginTransaction();

            try {
                $routeSummary = $this->persistRoutes($parsedRoutes);
                $quaySummary = $this->persistQuays($parsedQuays);
                $this->linkQuaysToRoutes($parsedLinks);

                $deactivationSummary = $this->deactivateMissingEntities(
                    seenGtfsRouteIds: array_map(static fn (ParsedRoute $r) => $r->gtfsRouteId, $parsedRoutes),
                    seenGtfsStopIds: array_map(static fn (ParsedQuay $q) => $q->gtfsStopId, $parsedQuays),
                );

                $this->entityManager->flush();
                $this->entityManager->commit();
            } catch (\Throwable $exception) {
                $this->entityManager->rollback();
                throw $exception;
            }

            return $routeSummary
                ->merge($quaySummary)
                ->merge($deactivationSummary);
        } finally {
            $this->filesystem->remove($extractPath);
        }
    }

    /**
     * Télécharge l'archive GTFS statique et l'extrait dans un dossier temporaire.
     *
     * @return string chemin d'extraction
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function downloadAndExtractFeed(): string
    {
        $response = $this->httpClient->request('GET', $this->gtfsStaticFeedUrl);

        $tmpZipPath = tempnam(sys_get_temp_dir(), 'gtfs_').'.zip';
        $this->filesystem->dumpFile($tmpZipPath, $response->getContent());

        $extractPath = tempnam(sys_get_temp_dir(), 'gtfs_extract_');
        $this->filesystem->remove($extractPath);
        $this->filesystem->mkdir($extractPath);

        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpZipPath)) {
            $this->filesystem->remove($tmpZipPath);
            throw InvalidGtfsFeedException::corruptedArchive($this->gtfsStaticFeedUrl);
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $this->filesystem->remove($tmpZipPath);

        return $extractPath;
    }

    /**
     * Vérifie que les fichiers GTFS nécessaires sont présents et non vides.
     */
    public function validateFeedContents(string $extractPath): void
    {
        foreach (self::REQUIRED_FILES as $filename) {
            $path = $extractPath.'/'.$filename;

            if (!$this->filesystem->exists($path)) {
                throw InvalidGtfsFeedException::missingFile($filename);
            }

            if (0 === filesize($path)) {
                throw InvalidGtfsFeedException::emptyFile($filename);
            }
        }
    }

    /**
     * Parse routes.txt.
     *
     * @return ParsedRoute[]
     */
    public function parseRoutes(string $extractPath): array
    {
        $parsedRoutes = [];

        foreach ($this->readCsv($extractPath.'/routes.txt') as $row) {
            $parsedRoutes[] = new ParsedRoute(
                gtfsRouteId: $row['route_id'],
                shortName: $row['route_short_name'],
                longName: '' !== $row['route_long_name'] ? $row['route_long_name'] : null,
                color: $row['route_color'],
                textColor: $row['route_text_color'],
            );
        }

        return $parsedRoutes;
    }

    /**
     * Parse stops.txt en ne retenant que les quais (location_type=0)
     * Les arrêts physiques (location_type=1) ne sont pas persistés en tant que tels,
     * ils ne servent qu'à renseigner parentStopId.
     *
     * @return ParsedQuay[]
     */
    public function parseQuays(string $extractPath): array
    {
        $parsedQuays = [];

        foreach ($this->readCsv($extractPath.'/stops.txt') as $row) {
            if ('0' !== $row['location_type']) {
                continue;
            }

            $parsedQuays[] = new ParsedQuay(
                gtfsStopId: $row['stop_id'],
                name: $row['stop_name'],
                latitude: (float) $row['stop_lat'],
                longitude: (float) $row['stop_lon'],
                parentStopId: $row['parent_station'],
            );
        }

        return $parsedQuays;
    }

    /**
     * Croise trips.txt et stop_times.txt pour déduire, par quai, les
     * lignes qui le desservent et les destinations réelles distinctes.
     *
     * @return ParsedQuayRouteLink[]
     */
    public function parseQuayRouteLinks(string $extractPath): array
    {
        // trip_id => ['route_id' => ..., 'headsign' => ...]
        $tripInfoByTripId = [];
        foreach ($this->readCsv($extractPath.'/trips.txt') as $row) {
            $tripInfoByTripId[$row['trip_id']] = [
                'route_id' => $row['route_id'],
                'headsign' => $row['trip_headsign'],
            ];
        }

        // "stopId|routeId" => Set des destinations distinctes
        $destinationsByStopAndRoute = [];

        foreach ($this->readCsv($extractPath.'/stop_times.txt') as $row) {
            $tripInfo = $tripInfoByTripId[$row['trip_id']] ?? null;

            if (null === $tripInfo) {
                continue;
            }

            $key = $row['stop_id'].'|'.$tripInfo['route_id'];
            $destinationsByStopAndRoute[$key][$tripInfo['headsign']] = true;
        }

        $parsedLinks = [];
        foreach ($destinationsByStopAndRoute as $key => $destinations) {
            [$gtfsStopId, $gtfsRouteId] = explode('|', $key, 2);

            $parsedLinks[] = new ParsedQuayRouteLink(
                gtfsStopId: $gtfsStopId,
                gtfsRouteId: $gtfsRouteId,
                destinations: array_keys($destinations),
            );
        }

        return $parsedLinks;
    }

    /**
     * Upsert des Route à partir des DTO parsés.
     *
     * @param ParsedRoute[] $parsedRoutes
     */
    public function persistRoutes(array $parsedRoutes): GtfsImportSummary
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
     * Upsert des Quay à partir des DTO parsés.
     *
     * @param ParsedQuay[] $parsedQuays
     */
    public function persistQuays(array $parsedQuays): GtfsImportSummary
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
     * Quay::$destinationsServed, à partir des liens déduits par
     * parseQuayRouteLinks().
     *
     * @param ParsedQuayRouteLink[] $parsedQuayRouteLinks
     */
    public function linkQuaysToRoutes(array $parsedQuayRouteLinks): void
    {
        $quayRepository = $this->entityManager->getRepository(Quay::class);
        $routeRepository = $this->entityManager->getRepository(Route::class);

        // Regroupe les destinations par quai avant assignation, un quai
        // pouvant apparaître dans plusieurs liens (un par ligne desservie).
        $destinationsByStopId = [];
        foreach ($parsedQuayRouteLinks as $link) {
            $destinationsByStopId[$link->gtfsStopId] ??= [];
            foreach ($link->destinations as $destination) {
                $destinationsByStopId[$link->gtfsStopId][$destination] = true;
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
            $quay?->setDestinationsServed(array_keys($destinations));
        }
    }

    /**
     * Désactive (sans supprimer) les Route/Quay absents du dernier
     * import, pour préserver les DisplayConfiguration existantes.
     *
     * @param string[] $seenGtfsRouteIds
     * @param string[] $seenGtfsStopIds
     */
    public function deactivateMissingEntities(array $seenGtfsRouteIds, array $seenGtfsStopIds): GtfsImportSummary
    {
        $summary = new GtfsImportSummary();

        $missingRoutes = $this->entityManager->getRepository(Route::class)
            ->createQueryBuilder('r')
            ->where('r.active = true')
            ->andWhere('r.gtfsRouteId NOT IN (:seenIds)')
            ->setParameter('seenIds', $seenGtfsRouteIds)
            ->getQuery()
            ->getResult();

        foreach ($missingRoutes as $route) {
            $route->markDeprecated();
            ++$summary->deactivated;
        }

        $missingQuays = $this->entityManager->getRepository(Quay::class)
            ->createQueryBuilder('q')
            ->where('q.active = true')
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
     * Lit un fichier CSV GTFS ligne par ligne (streaming, pas de
     * chargement intégral en mémoire — nécessaire pour stop_times.txt
     * qui peut atteindre plusieurs centaines de milliers de lignes),
     * en associant chaque ligne aux en-têtes.
     *
     * @return \Generator<array<string, string>>
     */
    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'r');

        if (false === $handle) {
            throw InvalidGtfsFeedException::emptyFile(basename($path));
        }

        // Retire le BOM UTF-8 éventuel en tête de fichier.
        $bom = fread($handle, 3);
        if ("\xEF\xBB\xBF" !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle, escape: '');

        if (false === $headers) {
            fclose($handle);

            return;
        }

        while (false !== ($row = fgetcsv($handle, escape: ''))) {
            if ([null] === $row || false === $row) {
                continue;
            }

            yield array_combine($headers, $row);
        }

        fclose($handle);
    }
}
