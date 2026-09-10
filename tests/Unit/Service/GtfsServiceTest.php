<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GtfsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
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

    private function fixturesPath(): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/Gtfs';
    }
}
