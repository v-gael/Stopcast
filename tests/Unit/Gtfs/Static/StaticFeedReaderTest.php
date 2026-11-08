<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gtfs\Static;

use App\Gtfs\Static\InvalidGtfsFeedException;
use App\Gtfs\Static\StaticFeed;
use App\Gtfs\Static\StaticFeedReader;
use PHPUnit\Framework\TestCase;

final class StaticFeedReaderTest extends TestCase
{
    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }
    }

    public function testReadsOneRoutePerCsvLine(): void
    {
        self::assertCount(2, $this->read()->routes);
    }

    public function testMapsRouteColumns(): void
    {
        $route = $this->read()->routes[0];

        self::assertSame('R1', $route->gtfsRouteId);
        self::assertSame('1', $route->shortName);
        self::assertSame('CENTRE VILLE-GARE NORD', $route->longName);
        self::assertSame('FF5500', $route->color);
        self::assertSame('FFFFFF', $route->textColor);
    }

    public function testConvertsEmptyRouteLongNameToNull(): void
    {
        self::assertNull($this->read()->routes[1]->longName);
    }

    public function testAppliesSpecDefaultsWhenOptionalRouteColumnsAreMissing(): void
    {
        $routes = $this->read('MissingOptionalColumns')->routes;

        self::assertCount(1, $routes);
        self::assertNull($routes[0]->longName);
        self::assertSame('FFFFFF', $routes[0]->color);
        self::assertSame('000000', $routes[0]->textColor);
    }

    public function testKeepsOnlyQuaysFromStops(): void
    {
        self::assertCount(2, $this->read()->quays);
    }

    public function testMapsQuayColumns(): void
    {
        $quay = $this->read()->quays[0];

        self::assertSame('S1', $quay->gtfsStopId);
        self::assertSame('CENTRE VILLE', $quay->name);
        self::assertSame(38.5, $quay->latitude);
        self::assertSame(-28.6, $quay->longitude);
        self::assertSame('P1', $quay->parentStopId);
    }

    public function testTreatsEmptyLocationTypeAsQuay(): void
    {
        $quays = $this->read('EmptyLocationType')->quays;

        self::assertCount(1, $quays);
        self::assertSame('S1', $quays[0]->gtfsStopId);
    }

    public function testTreatsStopsAsQuaysWhenOptionalColumnsAreMissing(): void
    {
        $quays = $this->read('MissingOptionalColumns')->quays;

        self::assertCount(1, $quays);
        self::assertSame('S1', $quays[0]->gtfsStopId);
        self::assertSame('', $quays[0]->parentStopId);
    }

    public function testToleratesRowsWithTooManyOrTooFewFields(): void
    {
        $quays = $this->read('MalformedRows')->quays;

        self::assertCount(2, $quays);
        self::assertSame('P1', $quays[0]->parentStopId);
        self::assertSame('S2', $quays[1]->gtfsStopId);
        self::assertSame('', $quays[1]->parentStopId);
    }

    public function testStripsTheUtf8ByteOrderMark(): void
    {
        $archive = $this->archive(overrides: [
            'routes.txt' => "\xEF\xBB\xBFroute_id,route_short_name\nR1,1\n",
        ]);

        self::assertSame('R1', new StaticFeedReader()->read($archive)->routes[0]->gtfsRouteId);
    }

    public function testMapsEachTripToItsRouteAndHeadsign(): void
    {
        $trips = $this->read()->trips;

        self::assertCount(4, $trips);
        self::assertSame('T2', $trips[1]->gtfsTripId);
        self::assertSame('R1', $trips[1]->gtfsRouteId);
        self::assertSame('ZONE INDUSTRIELLE', $trips[1]->headsign);
    }

    public function testConvertsEmptyTripHeadsignToNull(): void
    {
        $trips = $this->read()->trips;

        self::assertSame('T5', $trips[3]->gtfsTripId);
        self::assertNull($trips[3]->headsign);
    }

    public function testFindsTheTerminusOfEachTripByNumericStopSequence(): void
    {
        self::assertSame('S3', $this->read('UnorderedStopSequences')->trips[0]->terminusStopId);
    }

    public function testLeavesTerminusNullWhenTripHasNoStopTimes(): void
    {
        $trips = $this->read()->trips;

        self::assertSame('T5', $trips[3]->gtfsTripId);
        self::assertNull($trips[3]->terminusStopId);
    }

    public function testToleratesMissingHeadsignColumn(): void
    {
        $trips = $this->read('MissingOptionalColumns')->trips;

        self::assertCount(1, $trips);
        self::assertNull($trips[0]->headsign);
    }

    public function testAggregatesDistinctDestinationsForSameQuayAndRoute(): void
    {
        $link = $this->read()->quayRouteLinks[0];

        self::assertSame('S1', $link->gtfsStopId);
        self::assertSame('R1', $link->gtfsRouteId);
        self::assertSame(['CENTRE VILLE', 'ZONE INDUSTRIELLE'], $link->destinations);
    }

    public function testSkipsStopTimesWithUnknownTripId(): void
    {
        self::assertCount(2, $this->read()->quayRouteLinks);
    }

    public function testLinksRouteToQuayWithoutDestinationWhenTripHasNoHeadsign(): void
    {
        $links = $this->read('MissingOptionalColumns')->quayRouteLinks;

        self::assertCount(1, $links);
        self::assertSame('S1', $links[0]->gtfsStopId);
        self::assertSame('R1', $links[0]->gtfsRouteId);
        self::assertSame([], $links[0]->destinations);
    }

    public function testRejectsACorruptedArchive(): void
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'test_gtfs_');
        $this->archives[] = $archive;
        file_put_contents($archive, 'not a zip archive');

        $this->expectException(InvalidGtfsFeedException::class);

        new StaticFeedReader()->read($archive);
    }

    public function testRejectsAnArchiveWithoutARequiredFile(): void
    {
        $this->expectExceptionObject(InvalidGtfsFeedException::missingFile('stop_times.txt'));

        new StaticFeedReader()->read($this->archive(overrides: ['stop_times.txt' => null]));
    }

    public function testRejectsAnArchiveWithAnEmptyRequiredFile(): void
    {
        $this->expectExceptionObject(InvalidGtfsFeedException::emptyFile('trips.txt'));

        new StaticFeedReader()->read($this->archive(overrides: ['trips.txt' => '']));
    }

    /**
     * Les fixtures d'un cas ne contiennent que les fichiers qui diffèrent :
     * les autres viennent des fixtures de base.
     */
    private function read(?string $case = null): StaticFeed
    {
        return new StaticFeedReader()->read($this->archive($case));
    }

    /**
     * @param array<string, string|null> $overrides contenu par fichier, null pour l'omettre
     */
    private function archive(?string $case = null, array $overrides = []): string
    {
        $files = [];
        foreach ([$this->fixturesPath(), ...(null !== $case ? [$this->fixturesPath().'/'.$case] : [])] as $directory) {
            foreach (glob($directory.'/*.txt') ?: [] as $file) {
                $files[basename($file)] = (string) file_get_contents($file);
            }
        }

        $archive = (string) tempnam(sys_get_temp_dir(), 'test_gtfs_');
        $this->archives[] = $archive;

        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::OVERWRITE);
        foreach (array_merge($files, $overrides) as $name => $content) {
            if (null !== $content) {
                $zip->addFromString($name, $content);
            }
        }

        $zip->close();

        return $archive;
    }

    private function fixturesPath(): string
    {
        return \dirname(__DIR__, 3).'/Fixtures/Gtfs';
    }
}
