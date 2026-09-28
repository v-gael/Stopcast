<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\InvalidGtfsRealtimeFeedException;
use App\Service\GtfsRealtimeFetcher;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GtfsRealtimeFetcherTest extends TestCase
{
    public function testValidFeedIsCachedAndNotDownloadedAgain(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(self::serializedFeed())]);
        $fetcher = $this->fetcher($httpClient);

        $fetcher->fetchTripUpdates();

        $feed = $fetcher->fetchTripUpdates();

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertCount(1, $feed->getEntity());
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidFeedIsNotCachedAndNextCallDownloadsAgain(string $invalidBody): void
    {
        $httpClient = new MockHttpClient([new MockResponse($invalidBody), new MockResponse(self::serializedFeed())]);
        $fetcher = $this->fetcher($httpClient);

        try {
            $fetcher->fetchTripUpdates();
            self::fail('Un flux invalide doit lever une exception.');
        } catch (InvalidGtfsRealtimeFeedException) {
        }

        $feed = $fetcher->fetchTripUpdates();

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertCount(1, $feed->getEntity());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'page HTML renvoyée avec un statut 200' => ['<html><body>Service indisponible</body></html>'];
        yield 'corps vide' => [''];
        yield 'flux tronqué' => [substr(self::serializedFeed(), 0, -5)];
    }

    private function fetcher(MockHttpClient $httpClient): GtfsRealtimeFetcher
    {
        return new GtfsRealtimeFetcher(
            httpClient: $httpClient,
            cache: new ArrayAdapter(),
            tripUpdatesUrl: 'https://example.test/trip-updates.pb',
            cacheTtl: 20,
        );
    }

    private static function serializedFeed(): string
    {
        return new FeedMessage()
            ->setHeader(new FeedHeader()->setGtfsRealtimeVersion('2.0')->setTimestamp(1_790_000_000))
            ->setEntity([
                new FeedEntity()
                    ->setId('1')
                    ->setTripUpdate(new TripUpdate()->setTrip(new TripDescriptor()->setTripId('T1')->setRouteId('R1'))),
            ])
            ->serializeToString();
    }
}
