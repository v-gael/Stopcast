<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Static;

use App\Entity\Quay;
use App\Entity\Route;
use App\Gtfs\Static\InvalidGtfsFeedException;
use App\Gtfs\Static\StaticFeedReader;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

final class StaticReferenceImporterTest extends KernelTestCase
{
    use ResetDatabase;

    public function testReimportReplacesRoutesAndDestinationsOfEachQuay(): void
    {
        $this->importFeed($this->fixturesPath());
        $this->importFeed($this->fixturesPath('ServiceChanged'));

        $s1 = $this->findQuay('S1');
        self::assertSame(['R2'], $this->gtfsRouteIdsOf($s1));
        self::assertSame(['GARE SUD'], $s1->getDestinationsServed());

        $s2 = $this->findQuay('S2');
        self::assertSame([], $this->gtfsRouteIdsOf($s2));
        self::assertNull($s2->getDestinationsServed());
    }

    public function testImportLinksRoutesServedOnlyByTripsWithoutHeadsign(): void
    {
        $this->importFeed($this->fixturesPath('MissingOptionalColumns'));

        $s1 = $this->findQuay('S1');
        self::assertSame(['R1'], $this->gtfsRouteIdsOf($s1));
        self::assertNull($s1->getDestinationsServed());
    }

    public function testImportStoresTheHeadsignOfEachTrip(): void
    {
        $this->importFeed($this->fixturesPath());

        self::assertSame(
            ['T1' => 'CENTRE VILLE', 'T3' => 'GARE SUD', 'T5' => null],
            $this->headsignsOf(['T1', 'T3', 'T5', 'UNKNOWN']),
        );
    }

    public function testImportStoresTheTerminusOfEachTrip(): void
    {
        $this->importFeed($this->fixturesPath());

        self::assertSame(
            ['T1' => 'S1', 'T3' => 'S2', 'T5' => null],
            array_map(static fn (array $trip): ?string => $trip['terminusStopId'], $this->tripsOf(['T1', 'T3', 'T5', 'UNKNOWN'])),
        );
    }

    public function testReimportRemovesTripsMissingFromTheNewFeed(): void
    {
        $this->importFeed($this->fixturesPath());
        $this->importFeed($this->fixturesPath('ServiceChanged'));

        self::assertSame(['T3' => 'GARE SUD'], $this->headsignsOf(['T1', 'T2', 'T3', 'T5']));
    }

    public function testReimportDeprecatesQuaysAndRoutesMissingFromTheNewFeed(): void
    {
        $this->importFeed($this->fixturesPath());
        $this->importFeed($this->fixturesPath('QuayAndRouteRemoved'));

        self::assertTrue($this->findQuay('S1')->isActive());
        self::assertTrue($this->findRoute('R1')->isActive());

        $s2 = $this->findQuay('S2');
        self::assertFalse($s2->isActive());
        self::assertNotNull($s2->getDeprecatedAt());

        $r2 = $this->findRoute('R2');
        self::assertFalse($r2->isActive());
        self::assertNotNull($r2->getDeprecatedAt());
    }

    public function testReimportReactivatesQuaysAndRoutesBackInTheFeed(): void
    {
        $this->importFeed($this->fixturesPath());
        $this->importFeed($this->fixturesPath('QuayAndRouteRemoved'));
        $this->importFeed($this->fixturesPath());

        $s2 = $this->findQuay('S2');
        self::assertTrue($s2->isActive());
        self::assertNull($s2->getDeprecatedAt());

        $r2 = $this->findRoute('R2');
        self::assertTrue($r2->isActive());
        self::assertNull($r2->getDeprecatedAt());
    }

    public function testConditionalImportIsSkippedWhenTheFeedIsUnchanged(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(etag: '"v1"')), $cache)->import();
        $unchanged = new MockResponse('', ['http_code' => 304]);

        $summary = $this->importer(new MockHttpClient($unchanged), $cache)->import(onlyIfChanged: true);

        self::assertNull($summary);
        self::assertSame(['If-None-Match: "v1"'], $this->sentHeader($unchanged, 'if-none-match'));
        self::assertTrue($this->findQuay('S2')->isActive());
    }

    public function testConditionalImportHappensWhenTheServerSendsANewFile(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(etag: '"v1"')), $cache)->import();

        $summary = $this->importer(new MockHttpClient($this->feedResponse(etag: '"v2"', case: 'QuayAndRouteRemoved')), $cache)->import(onlyIfChanged: true);

        self::assertNotNull($summary);
        $this->entityManager()->clear();
        self::assertFalse($this->findQuay('S2')->isActive());
    }

    public function testAFailedImportDoesNotRecordItsValidators(): void
    {
        $cache = new ArrayAdapter();
        try {
            $this->importer(new MockHttpClient(new MockResponse('pas une archive', ['response_headers' => ['ETag' => '"v1"']])), $cache)->import();
            self::fail('The import should have failed.');
        } catch (InvalidGtfsFeedException) {
        }

        $next = $this->feedResponse();
        $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testValidatorsAreKeptPerFeedUrl(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(etag: '"v1"')), $cache)->import();

        $other = $this->feedResponse();
        $this->importer(new MockHttpClient($other), $cache, feedUrl: 'https://other.test/gtfs.zip')->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($other, 'if-none-match'));
    }

    public function testLastModifiedAloneIsSentBack(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(lastModified: 'Mon, 26 Oct 2026 14:27:05 GMT')), $cache)->import();

        $next = new MockResponse('', ['http_code' => 304]);
        $summary = $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertNull($summary);
        self::assertSame(['If-Modified-Since: Mon, 26 Oct 2026 14:27:05 GMT'], $this->sentHeader($next, 'if-modified-since'));
        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testAFeedWithoutValidatorsForgetsThePreviousOnes(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(etag: '"v1"')), $cache)->import();
        $this->importer(new MockHttpClient($this->feedResponse()), $cache)->import();

        $next = $this->feedResponse();
        $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testAnUnconditionalImportSendsNoValidator(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->feedResponse(etag: '"v1"')), $cache)->import();

        $next = $this->feedResponse(etag: '"v1"');
        self::assertNotNull($this->importer(new MockHttpClient($next), $cache)->import());

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    /**
     * @param string[] $gtfsTripIds
     *
     * @return array<string, string|null>
     */
    private function headsignsOf(array $gtfsTripIds): array
    {
        return array_map(static fn (array $trip): ?string => $trip['headsign'], $this->tripsOf($gtfsTripIds));
    }

    /**
     * @param string[] $gtfsTripIds
     *
     * @return array<string, array{headsign: string|null, terminusStopId: string|null}>
     */
    private function tripsOf(array $gtfsTripIds): array
    {
        $tripRepository = self::getContainer()->get(TripRepository::class);
        self::assertInstanceOf(TripRepository::class, $tripRepository);

        $trips = $tripRepository->findHeadsignsAndTerminiByGtfsTripIds($gtfsTripIds);
        ksort($trips);

        return $trips;
    }

    private function importFeed(string $fixturesPath): void
    {
        $this->importer(new MockHttpClient(new MockResponse($this->zip($fixturesPath))))->import();
        $this->entityManager()->clear();
    }

    private function importer(MockHttpClient $httpClient, ?CacheItemPoolInterface $cache = null, string $feedUrl = 'https://example.test/gtfs-static.zip'): StaticReferenceImporter
    {
        return new StaticReferenceImporter(
            httpClient: $httpClient,
            entityManager: $this->entityManager(),
            filesystem: new Filesystem(),
            reader: new StaticFeedReader(),
            cache: $cache ?? new ArrayAdapter(),
            logger: new NullLogger(),
            feedUrl: $feedUrl,
        );
    }

    /**
     * Archive des fixtures par défaut, avec les validateurs donnés.
     */
    private function feedResponse(?string $etag = null, ?string $lastModified = null, ?string $case = null): MockResponse
    {
        $headers = array_filter(['ETag' => $etag, 'Last-Modified' => $lastModified], static fn (?string $value): bool => null !== $value);

        return new MockResponse($this->zip($this->fixturesPath($case)), ['response_headers' => $headers]);
    }

    /**
     * @return list<string>
     */
    private function sentHeader(MockResponse $response, string $name): array
    {
        /** @var array<string, list<string>> $headers */
        $headers = $response->getRequestOptions()['normalized_headers'];

        return $headers[$name] ?? [];
    }

    private function findQuay(string $gtfsStopId): Quay
    {
        $quay = $this->entityManager()->getRepository(Quay::class)->findOneBy(['gtfsStopId' => $gtfsStopId]);
        self::assertInstanceOf(Quay::class, $quay);

        return $quay;
    }

    private function findRoute(string $gtfsRouteId): Route
    {
        $route = $this->entityManager()->getRepository(Route::class)->findOneBy(['gtfsRouteId' => $gtfsRouteId]);
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    /**
     * @return string[]
     */
    private function gtfsRouteIdsOf(Quay $quay): array
    {
        $ids = $quay->getRoutes()->map(static fn (Route $route): string => $route->getGtfsRouteId())->toArray();
        sort($ids);

        return $ids;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function zip(string $fixturesPath): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (glob($fixturesPath.'/*.txt') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    private function fixturesPath(?string $case = null): string
    {
        return \dirname(__DIR__, 3).'/Fixtures/Gtfs'.(null !== $case ? '/'.$case : '');
    }
}
