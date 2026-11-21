<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\GtfsRt\NextPassage;
use App\Gtfs\Realtime\NextPassageFinder;
use App\Gtfs\Realtime\PassageIndexBuilder;
use App\Gtfs\Realtime\PassageIndexRefresher;
use App\Gtfs\Realtime\PassageIndexStore;
use App\Gtfs\Realtime\TripUpdatesFeed;
use App\Monitoring\HealthcheckPinger;
use App\Monitoring\RealtimeFeedFailureLogger;
use App\Monitoring\RealtimeFeedMismatchLogger;
use App\Repository\QuayRepository;
use App\Repository\RouteRepository;
use App\Repository\TripRepository;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Index des passages construit sur un flux temps réel simulé, sans appel
 * réseau, à une heure fixée, et gardé en mémoire.
 */
trait RealtimeFeedTrait
{
    use RealtimeFeedMessageTrait;

    /**
     * Lecteur d'un index rafraîchi une fois sur ce flux.
     */
    private function nextPassageFinder(MockHttpClient $httpClient, int $limit = 5, ?MockClock $clock = null): NextPassageFinder
    {
        $clock ??= self::fixedClock();
        $store = new PassageIndexStore(new ArrayAdapter());

        ($this->passageIndexRefresher($httpClient, $store, $clock, limit: $limit))();

        return $this->passageIndexReader($store, $clock, $limit);
    }

    private function passageIndexReader(PassageIndexStore $store, MockClock $clock, int $limit = 5): NextPassageFinder
    {
        return new NextPassageFinder($store, self::tripUpdatesFeed(new MockHttpClient(), $clock), $clock, $limit);
    }

    private function passageIndexRefresher(
        MockHttpClient $httpClient,
        PassageIndexStore $store,
        MockClock $clock,
        int $limit = 5,
        ?LoggerInterface $logger = null,
        ?HealthcheckPinger $pinger = null,
        string $healthcheckUrl = '',
    ): PassageIndexRefresher {
        $feed = self::tripUpdatesFeed($httpClient, $clock);

        return new PassageIndexRefresher(
            builder: $this->passageIndexBuilder($httpClient, $clock, $logger),
            store: $store,
            tripUpdatesFeed: $feed,
            feedFailureLogger: new RealtimeFeedFailureLogger($logger ?? new NullLogger(), new ArrayAdapter(), $clock),
            pinger: $pinger ?? new HealthcheckPinger(new MockHttpClient(), new NullLogger()),
            clock: $clock,
            limit: $limit,
            healthcheckUrl: $healthcheckUrl,
        );
    }

    private function passageIndexBuilder(MockHttpClient $httpClient, ?MockClock $clock = null, ?LoggerInterface $logger = null): PassageIndexBuilder
    {
        $container = self::getContainer();
        $quayRepository = $container->get(QuayRepository::class);
        $routeRepository = $container->get(RouteRepository::class);
        $tripRepository = $container->get(TripRepository::class);
        self::assertInstanceOf(QuayRepository::class, $quayRepository);
        self::assertInstanceOf(RouteRepository::class, $routeRepository);
        self::assertInstanceOf(TripRepository::class, $tripRepository);

        $clock ??= self::fixedClock();

        return new PassageIndexBuilder(self::tripUpdatesFeed($httpClient, $clock), $quayRepository, $routeRepository, $tripRepository, new RealtimeFeedMismatchLogger($logger ?? new NullLogger(), new ArrayAdapter(), $clock), $clock);
    }

    private static function tripUpdatesFeed(MockHttpClient $httpClient, MockClock $clock): TripUpdatesFeed
    {
        return new TripUpdatesFeed($httpClient, $clock, 'https://gtfs-rt.test/trip-updates', 300);
    }

    private static function fixedClock(): MockClock
    {
        return new MockClock(self::NOW, date_default_timezone_get());
    }

    /**
     * TripUpdate d'un trajet de la ligne $routeId, avec ses passages.
     *
     * @param StopTimeUpdate[] $stopTimeUpdates
     */
    private function tripUpdate(string $tripId, array $stopTimeUpdates, string $routeId = 'R1'): FeedEntity
    {
        return new FeedEntity()
            ->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId($tripId)->setRouteId($routeId))
                ->setStopTimeUpdate($stopTimeUpdates));
    }

    /**
     * Départ du quai $stopId dans $minutes minutes, avec sa stop_sequence si elle est donnée.
     */
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
}
