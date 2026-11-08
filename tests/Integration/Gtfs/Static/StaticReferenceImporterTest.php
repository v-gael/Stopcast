<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Static;

use App\Entity\Quay;
use App\Entity\Route;
use App\Gtfs\Static\InvalidGtfsFeedException;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Repository\TripRepository;
use App\Tests\Support\StaticFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class StaticReferenceImporterTest extends KernelTestCase
{
    use ResetDatabase;
    use StaticFeedTrait;

    public function testReimportReplacesRoutesAndDestinationsOfEachQuay(): void
    {
        $this->importFeed();
        $this->importFeed('ServiceChanged');

        $s1 = $this->findQuay('S1');
        self::assertSame(['R2'], $this->gtfsRouteIdsOf($s1));
        self::assertSame(['GARE SUD'], $s1->getDestinationsServed());

        $s2 = $this->findQuay('S2');
        self::assertSame([], $this->gtfsRouteIdsOf($s2));
        self::assertNull($s2->getDestinationsServed());
    }

    public function testImportLinksRoutesServedOnlyByTripsWithoutHeadsign(): void
    {
        $this->importFeed('MissingOptionalColumns');

        $s1 = $this->findQuay('S1');
        self::assertSame(['R1'], $this->gtfsRouteIdsOf($s1));
        self::assertNull($s1->getDestinationsServed());
    }

    public function testImportStoresTheHeadsignOfEachTrip(): void
    {
        $this->importFeed();

        self::assertSame(
            ['T1' => 'CENTRE VILLE', 'T3' => 'GARE SUD', 'T5' => null],
            $this->headsignsOf(['T1', 'T3', 'T5', 'UNKNOWN']),
        );
    }

    public function testImportStoresTheTerminusOfEachTrip(): void
    {
        $this->importFeed();

        self::assertSame(
            ['T1' => 'S1', 'T3' => 'S2', 'T5' => null],
            array_map(static fn (array $trip): ?string => $trip['terminusStopId'], $this->tripsOf(['T1', 'T3', 'T5', 'UNKNOWN'])),
        );
    }

    public function testReimportRemovesTripsMissingFromTheNewFeed(): void
    {
        $this->importFeed();
        $this->importFeed('ServiceChanged');

        self::assertSame(['T3' => 'GARE SUD'], $this->headsignsOf(['T1', 'T2', 'T3', 'T5']));
    }

    public function testReimportDeprecatesQuaysAndRoutesMissingFromTheNewFeed(): void
    {
        $this->importFeed();
        $this->importFeed('QuayAndRouteRemoved');

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
        $this->importFeed();
        $this->importFeed('QuayAndRouteRemoved');
        $this->importFeed();

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
        $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v1"')), $cache)->import();
        $unchanged = new MockResponse('', ['http_code' => 304]);

        $summary = $this->importer(new MockHttpClient($unchanged), $cache)->import(onlyIfChanged: true);

        self::assertNull($summary);
        self::assertSame(['If-None-Match: "v1"'], $this->sentHeader($unchanged, 'if-none-match'));
        self::assertTrue($this->findQuay('S2')->isActive());
    }

    public function testConditionalImportHappensWhenTheServerSendsANewFile(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v1"')), $cache)->import();

        $summary = $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v2"', case: 'QuayAndRouteRemoved')), $cache)->import(onlyIfChanged: true);

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

        $next = $this->staticFeedResponse();
        $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testValidatorsAreKeptPerFeedUrl(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v1"')), $cache)->import();

        $other = $this->staticFeedResponse();
        $this->importer(new MockHttpClient($other), $cache, feedUrl: 'https://other.test/gtfs.zip')->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($other, 'if-none-match'));
    }

    public function testLastModifiedAloneIsSentBack(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->staticFeedResponse(lastModified: 'Mon, 26 Oct 2026 14:27:05 GMT')), $cache)->import();

        $next = new MockResponse('', ['http_code' => 304]);
        $summary = $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertNull($summary);
        self::assertSame(['If-Modified-Since: Mon, 26 Oct 2026 14:27:05 GMT'], $this->sentHeader($next, 'if-modified-since'));
        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testAFeedWithoutValidatorsForgetsThePreviousOnes(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v1"')), $cache)->import();
        $this->importer(new MockHttpClient($this->staticFeedResponse()), $cache)->import();

        $next = $this->staticFeedResponse();
        $this->importer(new MockHttpClient($next), $cache)->import(onlyIfChanged: true);

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testAnUnconditionalImportSendsNoValidator(): void
    {
        $cache = new ArrayAdapter();
        $this->importer(new MockHttpClient($this->staticFeedResponse(etag: '"v1"')), $cache)->import();

        $next = $this->staticFeedResponse(etag: '"v1"');
        self::assertNotNull($this->importer(new MockHttpClient($next), $cache)->import());

        self::assertSame([], $this->sentHeader($next, 'if-none-match'));
    }

    public function testA304ToAnUnconditionalRequestIsAnHttpError(): void
    {
        $this->expectException(RedirectionExceptionInterface::class);

        $this->importer(new MockHttpClient(new MockResponse('', ['http_code' => 304])))->import(onlyIfChanged: true);
    }

    public function testAFeedWithoutValidatorsIsLogged(): void
    {
        $logs = new TestHandler();

        $this->importer(new MockHttpClient($this->staticFeedResponse()), logger: new Logger('app', [$logs]))->import();

        self::assertTrue($logs->hasInfoThatContains('sends neither ETag nor Last-Modified'));
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

    /**
     * @param string|null $case jeu de fixtures (null : le jeu par défaut)
     */
    private function importFeed(?string $case = null): void
    {
        $this->importer(new MockHttpClient(new MockResponse($this->staticFeedArchive($case))))->import();
        $this->entityManager()->clear();
    }

    private function importer(MockHttpClient $httpClient, ?ArrayAdapter $cache = null, string $feedUrl = self::STATIC_FEED_URL, ?LoggerInterface $logger = null): StaticReferenceImporter
    {
        return $this->staticReferenceImporter($httpClient, $this->entityManager(), $cache, $logger, $feedUrl);
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
}
