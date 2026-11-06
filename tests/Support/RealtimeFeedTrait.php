<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gtfs\Realtime\NextPassageFinder;
use App\Gtfs\Realtime\TripUpdatesFeed;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Prochains passages branchés sur un flux temps réel simulé, sans appel réseau,
 * à une heure fixée.
 */
trait RealtimeFeedTrait
{
    use RealtimeFeedMessageTrait;

    private function nextPassageFinder(MockHttpClient $httpClient, int $limit = 5, ?MockClock $clock = null): NextPassageFinder
    {
        $container = self::getContainer();
        $routeRepository = $container->get(RouteRepository::class);
        $tripRepository = $container->get(TripRepository::class);
        self::assertInstanceOf(RouteRepository::class, $routeRepository);
        self::assertInstanceOf(TripRepository::class, $tripRepository);

        $clock ??= new MockClock(self::NOW, date_default_timezone_get());

        return new NextPassageFinder(
            tripUpdatesFeed: new TripUpdatesFeed($httpClient, $clock, 'https://gtfs-rt.test/trip-updates', 300),
            cache: new ArrayAdapter(),
            routeRepository: $routeRepository,
            tripRepository: $tripRepository,
            cacheTtl: 20,
            limit: $limit,
        );
    }
}
