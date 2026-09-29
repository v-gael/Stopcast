<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Static;

use App\Entity\Quay;
use App\Entity\Route;
use App\Gtfs\Static\StaticFeedReader;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        $importer = new StaticReferenceImporter(
            httpClient: new MockHttpClient(new MockResponse($this->zip($fixturesPath))),
            entityManager: $this->entityManager(),
            filesystem: new Filesystem(),
            reader: new StaticFeedReader(),
            feedUrl: 'https://example.test/gtfs-static.zip',
        );

        $importer->import();
        $this->entityManager()->clear();
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
