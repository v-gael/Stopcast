<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

final class NextPassageFinderTest extends KernelTestCase
{
    use RealtimeFeedTrait;
    use ResetDatabase;

    private Quay $quay;

    private Quay $otherQuay;

    protected function setUp(): void
    {
        $entityManager = $this->entityManager();

        $route = new Route('R1', '1', 'FF5500', 'FFFFFF');
        $route->setLongName('CENTRE VILLE-GARE NORD');

        $this->quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $this->otherQuay = new Quay('S2', 'GARE NORD', 38.5, -28.6, 'P2');

        $entityManager->persist($route);
        $entityManager->persist($this->quay);
        $entityManager->persist($this->otherQuay);
        $entityManager->persist(new Trip('T1', $route, 'CENTRE VILLE', 'S9'));
        $entityManager->persist(new Trip('T2', $route, 'ZONE INDUSTRIELLE', 'S9'));
        $entityManager->persist(new Trip('T3', $route, 'CENTRE VILLE', 'S1'));
        $entityManager->persist(new Trip('T5', $route, null));
        $entityManager->flush();
    }

    public function testEachPassageShowsTheHeadsignOfItsTrip(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 10)]),
        ]);

        self::assertSame(['CENTRE VILLE', 'ZONE INDUSTRIELLE'], $this->headsignsOf($passages));
    }

    public function testUnknownTripFallsBackToTheRouteLongName(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('TX', [$this->departureAt('S1', 5)])]);

        self::assertSame(['CENTRE VILLE-GARE NORD'], $this->headsignsOf($passages));
    }

    public function testTripWithoutHeadsignFallsBackToTheRouteLongName(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('T5', [$this->departureAt('S1', 5)])]);

        self::assertSame(['CENTRE VILLE-GARE NORD'], $this->headsignsOf($passages));
    }

    public function testPassagesAreSortedByExpectedTime(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T2', [$this->departureAt('S1', 10)]),
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
        ]);

        self::assertSame([self::at(5), self::at(10)], $this->timestampsOf($passages));
    }

    public function testPassageAlreadyGoneIsNotShown(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T1', [$this->departureAt('S1', -1)]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 0)]),
        ]);

        self::assertSame(['ZONE INDUSTRIELLE'], $this->headsignsOf($passages));
    }

    public function testDepartureWithoutTimeFallsBackToTheArrival(): void
    {
        $stopTimeUpdate = new StopTimeUpdate()
            ->setStopId('S1')
            ->setArrival(new StopTimeEvent()->setTime(self::at(5)))
            ->setDeparture(new StopTimeEvent()->setDelay(60));

        $passages = $this->passagesAtQuay([$this->tripUpdate('T1', [$stopTimeUpdate])]);

        self::assertSame([self::at(5)], $this->timestampsOf($passages));
    }

    public function testSkippedStopIsNotShown(): void
    {
        $stopTimeUpdate = $this->departureAt('S1', 5)
            ->setScheduleRelationship(StopScheduleRelationship::SKIPPED);

        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T1', [$stopTimeUpdate]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 10)]),
        ]);

        self::assertSame(['ZONE INDUSTRIELLE'], $this->headsignsOf($passages));
    }

    #[DataProvider('removedTripRelationships')]
    public function testRemovedTripIsNotShown(int $scheduleRelationship): void
    {
        $removedTrip = $this->tripUpdate('T1', [$this->departureAt('S1', 5)]);
        $removedTrip->getTripUpdate()?->getTrip()?->setScheduleRelationship($scheduleRelationship);

        $passages = $this->passagesAtQuay([
            $removedTrip,
            $this->tripUpdate('T2', [$this->departureAt('S1', 10)]),
        ]);

        self::assertSame(['ZONE INDUSTRIELLE'], $this->headsignsOf($passages));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function removedTripRelationships(): iterable
    {
        yield 'canceled' => [TripScheduleRelationship::CANCELED];
        yield 'deleted' => [TripScheduleRelationship::DELETED];
    }

    public function testTripEndingAtTheQuayIsNotShown(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T3', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('T1', [$this->departureAt('S1', 10)]),
        ]);

        self::assertSame(['CENTRE VILLE'], $this->headsignsOf($passages));
        self::assertCount(1, $passages);
    }

    public function testTripWithoutKnownTerminusIsShown(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T5', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('TX', [$this->departureAt('S1', 10)]),
        ]);

        self::assertCount(2, $passages);
    }

    public function testHiddenPassageDoesNotTakeASlotUnderTheLimit(): void
    {
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T3', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 10)]),
        ])), limit: 1);

        self::assertSame(['ZONE INDUSTRIELLE'], $this->headsignsOf($finder->forQuays([$this->quay])['S1']));
    }

    public function testEachQuayGetsItsOwnPassagesFromASingleDownload(): void
    {
        $httpClient = new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5), $this->departureAt('S2', 8)]),
            $this->tripUpdate('T2', [$this->departureAt('S2', 12)]),
        ]));

        $passagesByStopId = $this->nextPassageFinder($httpClient)->forQuays([$this->quay, $this->otherQuay]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame([self::at(5)], $this->timestampsOf($passagesByStopId['S1']));
        self::assertSame([self::at(8), self::at(12)], $this->timestampsOf($passagesByStopId['S2']));
    }

    public function testLimitAppliesToEachQuay(): void
    {
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5), $this->departureAt('S2', 6)]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 10), $this->departureAt('S2', 11)]),
        ])), limit: 1);

        $passagesByStopId = $finder->forQuays([$this->quay, $this->otherQuay]);

        self::assertSame([self::at(5)], $this->timestampsOf($passagesByStopId['S1']));
        self::assertSame([self::at(6)], $this->timestampsOf($passagesByStopId['S2']));
    }

    public function testQuayWithoutPassageGetsAnEmptyList(): void
    {
        $passagesByStopId = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
        ])))->forQuays([$this->quay, $this->otherQuay]);

        self::assertSame([], $passagesByStopId['S2']);
    }

    public function testNoQuayDoesNotDownloadTheFeed(): void
    {
        $httpClient = new MockHttpClient();

        self::assertSame([], $this->nextPassageFinder($httpClient)->forQuays([]));
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testQueryCountGrowsWithNeitherTripUpdatesNorQuays(): void
    {
        $queriesForOneTrip = $this->countQueries(fn () => $this->passagesAtQuay([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
        ]));
        $queriesForThreeTripsAtTwoQuays = $this->countQueries(fn () => $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('T2', [$this->departureAt('S2', 10)]),
            $this->tripUpdate('TX', [$this->departureAt('S1', 15)]),
        ])))->forQuays([$this->quay, $this->otherQuay]));

        self::assertSame($queriesForOneTrip, $queriesForThreeTripsAtTwoQuays);
    }

    public function testCountByStopIdCountsPassagesAsTheyWouldBeShown(): void
    {
        $skipped = $this->departureAt('S2', 5)
            ->setScheduleRelationship(StopScheduleRelationship::SKIPPED);
        $canceled = $this->tripUpdate('T2', [$this->departureAt('S2', 5)]);
        $canceled->getTripUpdate()?->getTrip()?->setScheduleRelationship(TripScheduleRelationship::CANCELED);

        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            // T3 se termine à S1 : seul son passage à S2 compte.
            $this->tripUpdate('T3', [$this->departureAt('S2', 2), $this->departureAt('S1', 6)]),
            $this->tripUpdate('T1', [$this->departureAt('S1', 5), $skipped, $this->departureAt('S3', -5)]),
            $this->tripUpdate('T5', [$this->departureAt('S1', 8), $this->departureAt('S2', 9)]),
            $this->tripUpdate('TX', [$this->departureAt('S1', 7)], 'RX'),
            $canceled,
        ])));

        $counts = $finder->countByStopId();
        ksort($counts);

        self::assertSame(['S1' => 2, 'S2' => 2], $counts);
    }

    public function testValidFeedIsCachedAndNotDownloadedAgain(): void
    {
        $httpClient = new MockHttpClient([
            self::feedResponse([$this->tripUpdate('T1', [$this->departureAt('S1', 5)])]),
        ]);
        $finder = $this->nextPassageFinder($httpClient);

        $finder->forQuays([$this->quay]);

        $passagesByStopId = $finder->forQuays([$this->quay]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertCount(1, $passagesByStopId['S1']);
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidFeedIsNotCachedAndNextCallDownloadsAgain(string $invalidBody): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($invalidBody),
            self::feedResponse([$this->tripUpdate('T1', [$this->departureAt('S1', 5)])]),
        ]);
        $finder = $this->nextPassageFinder($httpClient);

        try {
            $finder->forQuays([$this->quay]);
            self::fail('Un flux invalide doit lever une exception.');
        } catch (InvalidGtfsRealtimeFeedException) {
        }

        $passagesByStopId = $finder->forQuays([$this->quay]);

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertCount(1, $passagesByStopId['S1']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'page HTML renvoyée avec un statut 200' => ['<html><body>Service indisponible</body></html>'];
        yield 'corps vide' => [''];
        yield 'flux tronqué' => [substr(self::serializedFeed([]), 0, -5)];
    }

    private function countQueries(callable $callback): int
    {
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debugDataHolder);

        $this->entityManager()->clear();
        $debugDataHolder->reset();
        $callback();

        $queries = $debugDataHolder->getData()['default'] ?? [];
        self::assertIsArray($queries);

        return \count($queries);
    }

    /**
     * @param FeedEntity[] $entities
     *
     * @return NextPassage[]
     */
    private function passagesAtQuay(array $entities): array
    {
        return $this->nextPassageFinder(new MockHttpClient(self::feedResponse($entities)))->forQuays([$this->quay])['S1'];
    }

    /**
     * @param StopTimeUpdate[] $stopTimeUpdates
     */
    private function tripUpdate(string $tripId, array $stopTimeUpdates, string $routeId = 'R1'): FeedEntity
    {
        return new FeedEntity()
            ->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId($tripId)->setRouteId($routeId))
                ->setStopTimeUpdate($stopTimeUpdates));
    }

    private function departureAt(string $stopId, int $minutes): StopTimeUpdate
    {
        return new StopTimeUpdate()
            ->setStopId($stopId)
            ->setDeparture(new StopTimeEvent()->setTime(self::at($minutes)));
    }

    /**
     * @param NextPassage[] $passages
     *
     * @return list<string|null>
     */
    private function headsignsOf(array $passages): array
    {
        return array_values(array_map(static fn (NextPassage $passage): ?string => $passage->headsign, $passages));
    }

    /**
     * @param NextPassage[] $passages
     *
     * @return list<int>
     */
    private function timestampsOf(array $passages): array
    {
        return array_values(array_map(static fn (NextPassage $passage): int => $passage->expectedAt->getTimestamp(), $passages));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
