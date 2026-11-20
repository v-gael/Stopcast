<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gtfs\Realtime;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use App\Gtfs\Realtime\PassageIndexStore;
use App\Tests\Support\QueryCountTrait;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Depuis le 2026-11-20, le flux temps réel de TCAT ne reprend plus les
 * identifiants de son référentiel : `PAt01` pour le quai `0:PAt01`, `C2026456`
 * pour les trajets `PS : Lu Ma Je VeC2026456` et `PS : MeC2026456`. L'index
 * retrouve les deux formes, l'identifiant exact d'abord.
 */
#[ResetDatabase]
final class FeedIdentifierToleranceTest extends KernelTestCase
{
    use QueryCountTrait;
    use RealtimeFeedTrait;

    protected function setUp(): void
    {
        $entityManager = $this->entityManager();

        $route = new Route('1', '1', '009DE0', 'FFFFFF');
        $route->setLongName('CRENEY PRES TROYES-RIVIERE DE COR');

        $entityManager->persist($route);

        foreach (['0:PAt01', '0:CAD01', '30000', '0:BRO02', '0:BRo02'] as $gtfsStopId) {
            $entityManager->persist(new Quay($gtfsStopId, $gtfsStopId, 48.29, 4.06, 'P1'));
        }

        // Les variantes d'une même course, une par service, vont au même terminus.
        $entityManager->persist(new Trip('PS : Lu Ma Je VeC2026456', $route, 'CENTRE ADMINISTRATIF', '0:CAD01', 12));
        $entityManager->persist(new Trip('PS : MeC2026456', $route, 'CENTRE ADMINISTRATIF', '0:CAD01', 12));
        // Variantes en désaccord : aucune n'est retenue.
        $entityManager->persist(new Trip('PS : Lu Ma Je VeC2026999', $route, 'CENTRE ADMINISTRATIF', '0:CAD01', 12));
        $entityManager->persist(new Trip('PS : MeC2026999', $route, 'CRENEY CHATAIGNIER', '0:PAt01', 9));
        $entityManager->flush();
    }

    public function testStopIdWithoutThePrefixOfItsQuayIsIndexedOnThatQuay(): void
    {
        $index = $this->index([$this->tripUpdate('C2026456', [$this->departureAt('PAt01', 5)])]);

        self::assertSame(['0:PAt01'], array_keys($index));
    }

    public function testStopIdKnownAsItIsKeepsItsQuay(): void
    {
        $index = $this->index([
            $this->tripUpdate('C2026456', [$this->departureAt('30000', 5)]),
            $this->tripUpdate('C2026456', [$this->departureAt('0:PAt01', 6)]),
        ]);

        self::assertSame(['0:PAt01', '30000'], $this->sortedStopIds($index));
    }

    public function testStopIdMatchingIsCaseSensitive(): void
    {
        $index = $this->index([
            $this->tripUpdate('C2026456', [$this->departureAt('BRo02', 5)]),
            $this->tripUpdate('C2026456', [$this->departureAt('bro02', 6)]),
        ]);

        self::assertSame(['0:BRo02', 'bro02'], $this->sortedStopIds($index));
    }

    public function testCourseTakesTheHeadsignOfItsServiceVariants(): void
    {
        $index = $this->index([$this->tripUpdate('C2026456', [$this->departureAt('PAt01', 5)])]);

        self::assertSame(['CENTRE ADMINISTRATIF'], $this->headsignsOf($index['0:PAt01']));
    }

    public function testCourseArrivalAtTheTerminusOfItsServiceVariantsIsNotShown(): void
    {
        $index = $this->index([$this->tripUpdate('C2026456', [
            $this->departureAt('PAt01', 5, sequence: 3),
            $this->departureAt('CAD01', 20, sequence: 12),
        ])]);

        self::assertSame(['0:PAt01'], array_keys($index));
    }

    public function testCourseWhoseServiceVariantsDisagreeIsUnknown(): void
    {
        $index = $this->index([$this->tripUpdate('C2026999', [
            $this->departureAt('PAt01', 5, sequence: 9),
            $this->departureAt('CAD01', 20, sequence: 12),
        ])]);

        self::assertSame(['CRENEY PRES TROYES-RIVIERE DE COR'], $this->headsignsOf($index['0:PAt01']));
        self::assertSame(['CRENEY PRES TROYES-RIVIERE DE COR'], $this->headsignsOf($index['0:CAD01']));
    }

    public function testTripIdOfAnotherShapeIsNotMatchedBySuffix(): void
    {
        $index = $this->index([$this->tripUpdate('2026456', [$this->departureAt('PAt01', 5)])]);

        self::assertSame(['CRENEY PRES TROYES-RIVIERE DE COR'], $this->headsignsOf($index['0:PAt01']));
    }

    public function testExactTripIdNeedsNoSuffixQuery(): void
    {
        $exact = [$this->tripUpdate('PS : MeC2026456', [$this->departureAt('0:PAt01', 5)])];
        $bySuffix = [$this->tripUpdate('C2026456', [$this->departureAt('0:PAt01', 5)])];

        $exactQueries = $this->countQueries(fn () => $this->passageIndexBuilder(new MockHttpClient(self::feedResponse($exact)))->build());
        $suffixQueries = $this->countQueries(fn () => $this->passageIndexBuilder(new MockHttpClient(self::feedResponse($bySuffix)))->build());

        self::assertSame($exactQueries + 1, $suffixQueries);
    }

    public function testFeedMatchingNoKnownQuayIsWarnedThenReportedOncePerQuarterHour(): void
    {
        $clock = self::fixedClock();
        $logs = new TestHandler();
        $refresher = $this->passageIndexRefresher(
            new MockHttpClient(fn (): MockResponse => self::feedResponse([
                $this->tripUpdate('C2026456', [$this->departureAt('XYZ01', 5)]),
            ], $clock->now()->getTimestamp())),
            new PassageIndexStore(new ArrayAdapter()),
            $clock,
            logger: new Logger('app', [$logs]),
        );

        $refresher();
        $clock->sleep(20);
        $refresher();

        self::assertCount(2, $this->recordsAt($logs, Level::Warning));
        self::assertCount(1, $this->recordsAt($logs, Level::Error));
        self::assertTrue($logs->hasErrorThatContains('GTFS-RT feed matches no known quay.'));
    }

    public function testFeedWithAKnownQuayIsNotReported(): void
    {
        $logs = new TestHandler();
        $clock = self::fixedClock();
        $refresher = $this->passageIndexRefresher(
            new MockHttpClient(self::feedResponse([
                $this->tripUpdate('C2026456', [$this->departureAt('XYZ01', 5), $this->departureAt('PAt01', 7)]),
            ])),
            new PassageIndexStore(new ArrayAdapter()),
            $clock,
            logger: new Logger('app', [$logs]),
        );

        $refresher();

        self::assertSame([], $logs->getRecords());
    }

    /**
     * @param FeedEntity[] $entities
     *
     * @return array<string, list<NextPassage>>
     */
    private function index(array $entities): array
    {
        return $this->passageIndexBuilder(new MockHttpClient(self::feedResponse($entities)))->build()->passagesByStopId;
    }

    /**
     * @param StopTimeUpdate[] $stopTimeUpdates
     */
    private function tripUpdate(string $tripId, array $stopTimeUpdates): FeedEntity
    {
        return new FeedEntity()
            ->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId($tripId)->setRouteId('1'))
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
     * Les stop_id de l'index, triés. PHP change une clé numérique (`30000`)
     * en entier : la lecture par stop_id n'en dépend pas, la comparaison si.
     *
     * @param array<array-key, mixed> $index
     *
     * @return list<string>
     */
    private function sortedStopIds(array $index): array
    {
        $stopIds = array_map(strval(...), array_keys($index));
        sort($stopIds);

        return $stopIds;
    }

    /**
     * @return list<LogRecord>
     */
    private function recordsAt(TestHandler $logs, Level $level): array
    {
        return array_values(array_filter($logs->getRecords(), static fn (LogRecord $record): bool => $level === $record->level));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
