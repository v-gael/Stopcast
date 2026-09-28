<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use App\Service\NextPassageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class NextPassageResolverTest extends KernelTestCase
{
    use ResetDatabase;

    private Quay $quay;

    protected function setUp(): void
    {
        $entityManager = $this->entityManager();

        $route = new Route('R1', '1', 'FF5500', 'FFFFFF');
        $route->setLongName('CENTRE VILLE-GARE NORD');

        $this->quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');

        $entityManager->persist($route);
        $entityManager->persist($this->quay);
        $entityManager->persist(new Trip('T1', $route, 'CENTRE VILLE'));
        $entityManager->persist(new Trip('T2', $route, 'ZONE INDUSTRIELLE'));
        $entityManager->persist(new Trip('T5', $route, null));
        $entityManager->flush();
    }

    public function testEachPassageShowsTheHeadsignOfItsTrip(): void
    {
        $passages = $this->resolve([
            ['T1', 'R1', 'S1', '+5 minutes'],
            ['T2', 'R1', 'S1', '+10 minutes'],
        ]);

        self::assertSame(['CENTRE VILLE', 'ZONE INDUSTRIELLE'], $this->headsignsOf($passages));
    }

    public function testUnknownTripFallsBackToTheRouteLongName(): void
    {
        $passages = $this->resolve([['TX', 'R1', 'S1', '+5 minutes']]);

        self::assertSame(['CENTRE VILLE-GARE NORD'], $this->headsignsOf($passages));
    }

    public function testTripWithoutHeadsignFallsBackToTheRouteLongName(): void
    {
        $passages = $this->resolve([['T5', 'R1', 'S1', '+5 minutes']]);

        self::assertSame(['CENTRE VILLE-GARE NORD'], $this->headsignsOf($passages));
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfTripUpdates(): void
    {
        $queriesForOneTrip = $this->countQueries(fn () => $this->resolve([
            ['T1', 'R1', 'S1', '+5 minutes'],
        ]));
        $queriesForThreeTrips = $this->countQueries(fn () => $this->resolve([
            ['T1', 'R1', 'S1', '+5 minutes'],
            ['T2', 'R1', 'S1', '+10 minutes'],
            ['TX', 'R1', 'S1', '+15 minutes'],
        ]));

        self::assertSame($queriesForOneTrip, $queriesForThreeTrips);
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
     * @param list<array{string, string, string, string}> $tripUpdates [trip_id, route_id, stop_id, heure relative]
     *
     * @return NextPassage[]
     */
    private function resolve(array $tripUpdates): array
    {
        $entities = [];
        foreach ($tripUpdates as $i => [$tripId, $routeId, $stopId, $relativeTime]) {
            $stopTimeUpdate = new StopTimeUpdate()
                ->setStopId($stopId)
                ->setDeparture(new StopTimeEvent()->setTime(new \DateTimeImmutable($relativeTime)->getTimestamp()));

            $entities[] = new FeedEntity()
                ->setId((string) $i)
                ->setTripUpdate(new TripUpdate()
                    ->setTrip(new TripDescriptor()->setTripId($tripId)->setRouteId($routeId))
                    ->setStopTimeUpdate([$stopTimeUpdate]));
        }

        $resolver = self::getContainer()->get(NextPassageResolver::class);
        self::assertInstanceOf(NextPassageResolver::class, $resolver);

        return $resolver->resolveForQuay(new FeedMessage()->setEntity($entities), $this->quay);
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

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
