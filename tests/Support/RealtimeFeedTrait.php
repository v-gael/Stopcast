<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gtfs\Realtime\NextPassageFinder;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Prochains passages branchés sur un flux temps réel simulé, sans appel réseau,
 * à une heure fixée.
 */
trait RealtimeFeedTrait
{
    private const string NOW = '2026-09-28 08:00:00';

    private function nextPassageFinder(MockHttpClient $httpClient, int $limit = 5): NextPassageFinder
    {
        $container = self::getContainer();
        $routeRepository = $container->get(RouteRepository::class);
        $tripRepository = $container->get(TripRepository::class);
        self::assertInstanceOf(RouteRepository::class, $routeRepository);
        self::assertInstanceOf(TripRepository::class, $tripRepository);

        return new NextPassageFinder(
            httpClient: $httpClient,
            cache: new ArrayAdapter(),
            routeRepository: $routeRepository,
            tripRepository: $tripRepository,
            clock: new MockClock(self::NOW),
            tripUpdatesUrl: 'https://gtfs-rt.test/trip-updates',
            cacheTtl: 20,
            limit: $limit,
        );
    }

    /**
     * @param FeedEntity[] $entities identifiés par leur rang dans le flux
     */
    private static function feedResponse(array $entities): MockResponse
    {
        return new MockResponse(self::serializedFeed($entities));
    }

    /**
     * @param FeedEntity[] $entities identifiés par leur rang dans le flux
     */
    private static function serializedFeed(array $entities): string
    {
        foreach (array_values($entities) as $i => $entity) {
            $entity->setId((string) $i);
        }

        return new FeedMessage()
            ->setHeader(new FeedHeader()->setGtfsRealtimeVersion('2.0')->setTimestamp(self::at(0)))
            ->setEntity(array_values($entities))
            ->serializeToString();
    }

    /**
     * Timestamp à quelques minutes de l'heure fixée (négatif : dans le passé).
     */
    private static function at(int $minutes): int
    {
        return new \DateTimeImmutable(self::NOW)->getTimestamp() + $minutes * 60;
    }
}
