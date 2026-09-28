<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\DevelopmentQuaySelector;
use App\Repository\QuayRepository;
use App\Service\GtfsRealtimeFetcher;
use App\Service\NextPassageResolver;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sélecteur branché sur un flux temps réel simulé, sans appel réseau.
 */
trait DevelopmentQuaySelectorFactoryTrait
{
    /**
     * @param array<string, list<string>> $stopIdsByTripId trajets du flux (route R1), avec les quais desservis dans 5 minutes
     */
    private function selectorWithFeed(array $stopIdsByTripId): DevelopmentQuaySelector
    {
        $entities = [];
        foreach ($stopIdsByTripId as $tripId => $stopIds) {
            $entities[] = new FeedEntity()
                ->setId((string) $tripId)
                ->setTripUpdate(new TripUpdate()
                    ->setTrip(new TripDescriptor()->setTripId((string) $tripId)->setRouteId('R1'))
                    ->setStopTimeUpdate(array_map(
                        static fn (string $stopId): StopTimeUpdate => new StopTimeUpdate()
                            ->setStopId($stopId)
                            ->setDeparture(new StopTimeEvent()->setTime(new \DateTimeImmutable('+5 minutes')->getTimestamp())),
                        $stopIds,
                    )));
        }

        $feed = new FeedMessage()
            ->setHeader(new FeedHeader()->setGtfsRealtimeVersion('2.0'))
            ->setEntity($entities);

        return $this->selector(new MockResponse($feed->serializeToString()));
    }

    private function selectorWithUnavailableFeed(): DevelopmentQuaySelector
    {
        return $this->selector(new MockResponse('', ['http_code' => 503]));
    }

    private function selector(MockResponse $response): DevelopmentQuaySelector
    {
        $container = self::getContainer();
        $resolver = $container->get(NextPassageResolver::class);
        $quayRepository = $container->get(QuayRepository::class);
        self::assertInstanceOf(NextPassageResolver::class, $resolver);
        self::assertInstanceOf(QuayRepository::class, $quayRepository);

        $fetcher = new GtfsRealtimeFetcher(new MockHttpClient([$response]), new ArrayAdapter(), 'https://gtfs-rt.test/trip-updates', 20);

        return new DevelopmentQuaySelector($fetcher, $resolver, $quayRepository);
    }
}
