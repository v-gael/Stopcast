<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Gtfs\Realtime\PassageIndexStore;
use App\Tests\Support\HealthcheckPingsTrait;
use App\Tests\Support\QueryCountTrait;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * L'index des passages, rafraîchi par PassageIndexRefresher puis lu par
 * NextPassageFinder, comme realtime-worker et les écrans le font.
 */
final class NextPassageFinderTest extends KernelTestCase
{
    use HealthcheckPingsTrait;
    use QueryCountTrait;
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
        // Boucle : part de S1 (séquence 1) et y revient (séquence 19).
        $entityManager->persist(new Trip('T6', $route, 'BOUCLE', 'S1', 19));
        $entityManager->persist(new Trip('T7', $route, 'CENTRE VILLE', 'S1', 6));
        // Boucle importée avant l'enregistrement de la séquence du terminus.
        $entityManager->persist(new Trip('T8', $route, 'BOUCLE', 'S1'));
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

    public function testCircularTripDepartureFromItsTerminusQuayIsShown(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('T6', [$this->departureAt('S1', 5, sequence: 1)])]);

        self::assertSame(['BOUCLE'], $this->headsignsOf($passages));
    }

    public function testCircularTripArrivalAtItsTerminusQuayIsNotShown(): void
    {
        // Trajet en cours : seule l'arrivée au quai d'origine reste dans le flux.
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T6', [$this->departureAt('S2', 2, sequence: 10), $this->departureAt('S1', 5, sequence: 19)]),
        ]);

        self::assertSame([], $passages);
    }

    public function testCircularTripPublishedWithDepartureAndArrivalIsShownOnce(): void
    {
        // Relevé au moment du départ : le quai d'origine figure en séquences 1 et 19.
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T6', [$this->departureAt('S1', 1, sequence: 1), $this->departureAt('S1', 29, sequence: 19)]),
        ]);

        self::assertSame([self::at(1)], $this->timestampsOf($passages));
    }

    public function testCircularTripWhoseDepartureIsGoneShowsNothingAtItsTerminusQuay(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T6', [$this->departureAt('S1', -1, sequence: 1), $this->departureAt('S1', 27, sequence: 19)]),
        ]);

        self::assertSame([], $passages);
    }

    public function testPassageAtTheTerminusQuayWithoutSequenceInTheFeedIsNotShown(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('T6', [$this->departureAt('S1', 5)])]);

        self::assertSame([], $passages);
    }

    public function testPassageAtTheTerminusQuayOfATripWithoutStoredSequenceIsNotShown(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('T8', [$this->departureAt('S1', 5, sequence: 1)])]);

        self::assertSame([], $passages);
    }

    public function testTripEndingAtTheQuayWithSequenceIsNotShown(): void
    {
        $passages = $this->passagesAtQuay([$this->tripUpdate('T7', [$this->departureAt('S1', 5, sequence: 6)])]);

        self::assertSame([], $passages);
    }

    public function testQuayRepeatedConsecutivelyInATripIsShownOnce(): void
    {
        $passages = $this->passagesAtQuay([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5, sequence: 3), $this->departureAt('S1', 6, sequence: 4)]),
        ]);

        self::assertSame([self::at(5)], $this->timestampsOf($passages));
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

    public function testNoQuayReadsNothing(): void
    {
        $finder = $this->passageIndexReader(new PassageIndexStore(new ArrayAdapter()), self::fixedClock());

        self::assertSame([], $finder->forQuays([]));
    }

    public function testOnlyTheRefreshDownloadsTheFeed(): void
    {
        $httpClient = new MockHttpClient(self::feedResponse([$this->tripUpdate('T1', [$this->departureAt('S1', 5)])]));
        $finder = $this->nextPassageFinder($httpClient);

        $first = $finder->forQuays([$this->quay]);
        $second = $finder->forQuays([$this->quay, $this->otherQuay]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertEquals($first['S1'], $second['S1']);
    }

    public function testReadingRunsNoQuery(): void
    {
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('TX', [$this->departureAt('S2', 15)]),
        ])));

        self::assertSame(0, $this->countQueries(fn () => $finder->forQuays([$this->quay, $this->otherQuay])));
    }

    public function testBuildingRunsTheSameQueriesWhateverTheTripUpdatesAndQuays(): void
    {
        $queriesForOneTrip = $this->countQueries(fn () => $this->passageIndexBuilder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
        ])))->build());
        $queriesForThreeTripsAtTwoQuays = $this->countQueries(fn () => $this->passageIndexBuilder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 5)]),
            $this->tripUpdate('T2', [$this->departureAt('S2', 10)]),
            $this->tripUpdate('TX', [$this->departureAt('S1', 15)]),
        ])))->build());

        self::assertSame($queriesForOneTrip, $queriesForThreeTripsAtTwoQuays);
    }

    public function testBuilderKeepsEveryDisplayablePassageOfEveryQuayWithoutLimit(): void
    {
        $skipped = $this->departureAt('S2', 5)
            ->setScheduleRelationship(StopScheduleRelationship::SKIPPED);
        $canceled = $this->tripUpdate('T2', [$this->departureAt('S2', 5)]);
        $canceled->getTripUpdate()?->getTrip()?->setScheduleRelationship(TripScheduleRelationship::CANCELED);

        $index = $this->passageIndexBuilder(new MockHttpClient(self::feedResponse([
            // T3 se termine à S1 : seul son passage à S2 compte.
            $this->tripUpdate('T3', [$this->departureAt('S2', 2), $this->departureAt('S1', 6)]),
            $this->tripUpdate('T1', [$this->departureAt('S1', 5), $skipped, $this->departureAt('S3', -5)]),
            $this->tripUpdate('T5', [$this->departureAt('S1', 8), $this->departureAt('S2', 9)]),
            $this->tripUpdate('TX', [$this->departureAt('S1', 7)], 'RX'),
            $canceled,
        ])))->build();

        self::assertSame(self::at(0), $index->feedTimestamp);
        $stopIds = array_keys($index->passagesByStopId);
        sort($stopIds);

        self::assertSame(['S1', 'S2'], $stopIds);
        self::assertSame([self::at(5), self::at(8)], $this->timestampsOf($index->passagesByStopId['S1']));
        self::assertSame([self::at(2), self::at(9)], $this->timestampsOf($index->passagesByStopId['S2']));
    }

    public function testPassageGoneSinceTheRefreshLeavesItsSlotToTheNextOne(): void
    {
        $clock = self::fixedClock();
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 2)]),
            $this->tripUpdate('T2', [$this->departureAt('S1', 4)]),
        ])), limit: 1, clock: $clock);

        $clock->sleep(2 * 60 + 1);

        self::assertSame([self::at(4)], $this->timestampsOf($finder->forQuays([$this->quay])['S1']));
    }

    public function testIndexKeepsThreeTimesTheLimitPerQuay(): void
    {
        $clock = self::fixedClock();
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse(array_map(
            fn (int $minutes): FeedEntity => $this->tripUpdate('TX'.$minutes, [$this->departureAt('S1', $minutes)]),
            [1, 2, 3, 4],
        ))), limit: 1, clock: $clock);

        $clock->sleep(2 * 60 + 1);
        self::assertSame([self::at(3)], $this->timestampsOf($finder->forQuays([$this->quay])['S1']));

        $clock->sleep(60);
        self::assertSame([], $finder->forQuays([$this->quay])['S1']);
    }

    public function testMissingIndexIsRefused(): void
    {
        $finder = $this->passageIndexReader(new PassageIndexStore(new ArrayAdapter()), self::fixedClock());

        $this->expectException(InvalidGtfsRealtimeFeedException::class);
        $this->expectExceptionMessage('absent');

        $finder->forQuays([$this->quay]);
    }

    public function testIndexOlderThanTheMaximumAgeIsRefused(): void
    {
        $clock = self::fixedClock();
        $finder = $this->nextPassageFinder(new MockHttpClient(self::feedResponse([
            $this->tripUpdate('T1', [$this->departureAt('S1', 10)]),
        ], self::at(0) - 290)), clock: $clock);

        self::assertCount(1, $finder->forQuays([$this->quay])['S1']);

        $clock->sleep(15);

        $this->expectException(InvalidGtfsRealtimeFeedException::class);
        $this->expectExceptionMessage('périmé');

        $finder->forQuays([$this->quay]);
    }

    public function testStaleFeedReplacesTheIndexButIsRefusedLoggedAndNotPinged(): void
    {
        $clock = self::fixedClock();
        $store = new PassageIndexStore(new ArrayAdapter());
        $logs = new TestHandler();
        $refresher = $this->passageIndexRefresher(new MockHttpClient([
            self::feedResponse([$this->tripUpdate('T1', [$this->departureAt('S1', 5)])]),
            self::feedResponse([$this->tripUpdate('T2', [$this->departureAt('S1', 10)])], self::at(-10)),
        ]), $store, $clock, logger: new Logger('app', [$logs]), pinger: $this->recordingPinger(), healthcheckUrl: 'https://hc-ping.test/realtime');

        $refresher();
        $clock->sleep(60);
        $refresher();

        self::assertSame([self::at(10)], $this->timestampsOf($store->load()->passagesByStopId['S1'] ?? []));
        self::assertTrue($logs->hasWarningThatContains('GTFS-RT feed unavailable.'));
        self::assertSame(['https://hc-ping.test/realtime'], $this->pings);

        $this->expectException(InvalidGtfsRealtimeFeedException::class);
        $this->expectExceptionMessage('périmé');

        $this->passageIndexReader($store, $clock)->forQuays([$this->quay]);
    }

    #[DataProvider('failedDownloads')]
    public function testFailedRefreshKeepsTheLastIndexAndWarnsOnce(MockResponse $failedDownload): void
    {
        $clock = self::fixedClock();
        $store = new PassageIndexStore(new ArrayAdapter());
        $logs = new TestHandler();
        $refresher = $this->passageIndexRefresher(new MockHttpClient([
            self::feedResponse([$this->tripUpdate('T1', [$this->departureAt('S1', 5)])]),
            $failedDownload,
        ]), $store, $clock, logger: new Logger('app', [$logs]));

        $refresher();
        $refresher();

        $finder = $this->passageIndexReader($store, $clock);

        self::assertSame([self::at(5)], $this->timestampsOf($finder->forQuays([$this->quay])['S1']));
        self::assertCount(1, array_filter($logs->getRecords(), static fn (LogRecord $record): bool => Level::Warning === $record->level));
        self::assertTrue($logs->hasErrorThatContains('GTFS-RT feed unavailable.'));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function failedDownloads(): iterable
    {
        yield 'page HTML renvoyée avec un statut 200' => [new MockResponse('<html><body>Service indisponible</body></html>')];
        yield 'corps vide' => [new MockResponse('')];
        yield 'flux tronqué' => [new MockResponse(substr(self::serializedFeed([]), 0, -5))];
        yield 'erreur HTTP' => [new MockResponse('', ['http_code' => 503])];
    }

    public function testRefreshIsPingedAtMostOncePerMinute(): void
    {
        $clock = self::fixedClock();
        $refresher = $this->passageIndexRefresher(
            new MockHttpClient(static fn (): MockResponse => self::feedResponse([], $clock->now()->getTimestamp())),
            new PassageIndexStore(new ArrayAdapter()),
            $clock,
            pinger: $this->recordingPinger(),
            healthcheckUrl: 'https://hc-ping.test/realtime',
        );

        foreach (range(1, 4) as $refresh) {
            $refresher();
            $clock->sleep(20);
        }

        self::assertSame(['https://hc-ping.test/realtime', 'https://hc-ping.test/realtime'], $this->pings);
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

    private function departureAt(string $stopId, int $minutes, ?int $sequence = null): StopTimeUpdate
    {
        $stopTimeUpdate = new StopTimeUpdate()
            ->setStopId($stopId)
            ->setDeparture(new StopTimeEvent()->setTime(self::at($minutes)));

        return null === $sequence ? $stopTimeUpdate : $stopTimeUpdate->setStopSequence($sequence);
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
