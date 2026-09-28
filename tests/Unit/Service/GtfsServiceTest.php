<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\InvalidGtfsFeedException;
use App\Service\GtfsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GtfsServiceTest extends TestCase
{
    private GtfsService $gtfsService;

    protected function setUp(): void
    {
        $this->gtfsService = new GtfsService(
            httpClient: $this->createStub(HttpClientInterface::class),
            entityManager: $this->createStub(EntityManagerInterface::class),
            filesystem: $this->createStub(Filesystem::class),
            gtfsStaticFeedUrl: 'https://example.test/gtfs-static.zip',
        );
    }

    public function testParseRoutesReturnsOneParsedRoutePerCsvLine(): void
    {
        $parsedRoutes = $this->gtfsService->parseRoutes($this->fixturesPath());

        self::assertCount(2, $parsedRoutes);
    }

    public function testParseRoutesMapsCsvColumnsCorrectly(): void
    {
        $parsedRoutes = $this->gtfsService->parseRoutes($this->fixturesPath());

        $firstRoute = $parsedRoutes[0];

        self::assertSame('R1', $firstRoute->gtfsRouteId);
        self::assertSame('1', $firstRoute->shortName);
        self::assertSame('CENTRE VILLE-GARE NORD', $firstRoute->longName);
        self::assertSame('FF5500', $firstRoute->color);
        self::assertSame('FFFFFF', $firstRoute->textColor);
    }

    public function testParseRoutesConvertsEmptyLongNameToNull(): void
    {
        $parsedRoutes = $this->gtfsService->parseRoutes($this->fixturesPath());

        self::assertNull($parsedRoutes[1]->longName);
    }

    public function testParseRoutesAppliesSpecDefaultsWhenOptionalColumnsAreMissing(): void
    {
        $parsedRoutes = $this->gtfsService->parseRoutes($this->fixturesPath('MissingOptionalColumns'));

        self::assertCount(1, $parsedRoutes);
        self::assertNull($parsedRoutes[0]->longName);
        self::assertSame('FFFFFF', $parsedRoutes[0]->color);
        self::assertSame('000000', $parsedRoutes[0]->textColor);
    }

    public function testParseQuaysFiltersOutParentStations(): void
    {
        $parsedQuays = $this->gtfsService->parseQuays($this->fixturesPath());

        self::assertCount(2, $parsedQuays);
    }

    public function testParseQuaysMapsCsvColumnsCorrectly(): void
    {
        $parsedQuays = $this->gtfsService->parseQuays($this->fixturesPath());

        $firstQuay = $parsedQuays[0];

        self::assertSame('S1', $firstQuay->gtfsStopId);
        self::assertSame('CENTRE VILLE', $firstQuay->name);
        self::assertSame(38.5, $firstQuay->latitude);
        self::assertSame(-28.6, $firstQuay->longitude);
        self::assertSame('P1', $firstQuay->parentStopId);
    }

    public function testParseQuaysTreatsEmptyLocationTypeAsQuay(): void
    {
        $parsedQuays = $this->gtfsService->parseQuays($this->fixturesPath('EmptyLocationType'));

        self::assertCount(1, $parsedQuays);
        self::assertSame('S1', $parsedQuays[0]->gtfsStopId);
    }

    public function testParseQuaysTreatsStopsAsQuaysWhenOptionalColumnsAreMissing(): void
    {
        $parsedQuays = $this->gtfsService->parseQuays($this->fixturesPath('MissingOptionalColumns'));

        self::assertCount(1, $parsedQuays);
        self::assertSame('S1', $parsedQuays[0]->gtfsStopId);
        self::assertSame('', $parsedQuays[0]->parentStopId);
    }

    public function testParseQuaysToleratesRowsWithTooManyOrTooFewFields(): void
    {
        $parsedQuays = $this->gtfsService->parseQuays($this->fixturesPath('MalformedRows'));

        self::assertCount(2, $parsedQuays);
        self::assertSame('P1', $parsedQuays[0]->parentStopId);
        self::assertSame('S2', $parsedQuays[1]->gtfsStopId);
        self::assertSame('', $parsedQuays[1]->parentStopId);
    }

    public function testParseQuayRouteLinksAggregatesDistinctDestinationsForSameStopAndRoute(): void
    {
        $parsedLinks = $this->gtfsService->parseQuayRouteLinks($this->fixturesPath());

        $s1R1Link = $parsedLinks[0];

        self::assertSame('S1', $s1R1Link->gtfsStopId);
        self::assertSame('R1', $s1R1Link->gtfsRouteId);
        self::assertSame(['CENTRE VILLE', 'ZONE INDUSTRIELLE'], $s1R1Link->destinations);
    }

    public function testParseQuayRouteLinksSkipsStopTimesWithUnknownTripId(): void
    {
        $parsedLinks = $this->gtfsService->parseQuayRouteLinks($this->fixturesPath());

        self::assertCount(2, $parsedLinks);
    }

    public function testDownloadAndExtractFeedLeavesNoTemporaryFileBehind(): void
    {
        $filesystem = new Filesystem();
        $gtfsService = new GtfsService(
            httpClient: new MockHttpClient(new MockResponse($this->zipFixtures())),
            entityManager: $this->createStub(EntityManagerInterface::class),
            filesystem: $filesystem,
            gtfsStaticFeedUrl: 'https://example.test/gtfs-static.zip',
        );

        $tempEntriesBefore = glob(sys_get_temp_dir().'/gtfs_*') ?: [];

        $extractPath = $gtfsService->downloadAndExtractFeed();

        try {
            $tempEntriesAfter = glob(sys_get_temp_dir().'/gtfs_*') ?: [];

            self::assertSame([$extractPath], array_values(array_diff($tempEntriesAfter, $tempEntriesBefore)));
            self::assertFileExists($extractPath.'/stops.txt');
        } finally {
            $filesystem->remove($extractPath);
        }
    }

    public function testDownloadAndExtractFeedLeavesNoTemporaryFileBehindWhenArchiveIsCorrupted(): void
    {
        $gtfsService = new GtfsService(
            httpClient: new MockHttpClient(new MockResponse('not a zip archive')),
            entityManager: $this->createStub(EntityManagerInterface::class),
            filesystem: new Filesystem(),
            gtfsStaticFeedUrl: 'https://example.test/gtfs-static.zip',
        );

        $tempEntriesBefore = glob(sys_get_temp_dir().'/gtfs_*') ?: [];

        try {
            $gtfsService->downloadAndExtractFeed();
            self::fail('Une archive corrompue doit lever InvalidGtfsFeedException.');
        } catch (InvalidGtfsFeedException) {
        }

        self::assertSame($tempEntriesBefore, glob(sys_get_temp_dir().'/gtfs_*') ?: []);
    }

    public function testDownloadAndExtractFeedLeavesNoTemporaryFileBehindWhenDownloadFails(): void
    {
        $gtfsService = new GtfsService(
            httpClient: new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            entityManager: $this->createStub(EntityManagerInterface::class),
            filesystem: new Filesystem(),
            gtfsStaticFeedUrl: 'https://example.test/gtfs-static.zip',
        );

        $tempEntriesBefore = glob(sys_get_temp_dir().'/gtfs_*') ?: [];

        try {
            $gtfsService->downloadAndExtractFeed();
            self::fail('Une erreur HTTP doit remonter.');
        } catch (ServerException) {
        }

        self::assertSame($tempEntriesBefore, glob(sys_get_temp_dir().'/gtfs_*') ?: []);
    }

    private function zipFixtures(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (glob($this->fixturesPath().'/*.txt') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    private function fixturesPath(?string $case = null): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/Gtfs'.(null !== $case ? '/'.$case : '');
    }
}
