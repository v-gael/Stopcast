<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gtfs\Realtime;

use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Gtfs\Realtime\TripUpdatesFeed;
use App\Tests\Support\RealtimeFeedMessageTrait;
use Google\Transit\Realtime\FeedEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class TripUpdatesFeedTest extends TestCase
{
    use RealtimeFeedMessageTrait;

    public function testAFreshFeedIsReturned(): void
    {
        $feed = $this->feed()->parse(self::serializedFeed([new FeedEntity(), new FeedEntity()], self::at(-1)));

        self::assertCount(2, $feed->getEntity());
    }

    public function testAFeedOlderThanTheMaximumAgeIsStale(): void
    {
        $this->expectException(InvalidGtfsRealtimeFeedException::class);
        $this->expectExceptionMessage('Flux GTFS-RT périmé : "https://gtfs-rt.test/trip-updates" (âge 301 s, maximum 300 s).');

        $this->feed()->parse(self::serializedFeed([], self::at(0) - 301));
    }

    public function testAFeedExactlyAtTheMaximumAgeIsStillFresh(): void
    {
        $feed = $this->feed()->parse(self::serializedFeed([new FeedEntity()], self::at(0) - 300));

        self::assertCount(1, $feed->getEntity());
    }

    #[DataProvider('timestampsCountedAsFresh')]
    public function testAFeedWithoutAUsableTimestampIsFresh(int $headerTimestamp): void
    {
        $feed = $this->feed()->parse(self::serializedFeed([new FeedEntity()], $headerTimestamp));

        self::assertCount(1, $feed->getEntity());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timestampsCountedAsFresh(): iterable
    {
        yield 'timestamp absent (0)' => [0];
        yield 'timestamp dans le futur' => [self::at(10)];
    }

    private function feed(): TripUpdatesFeed
    {
        return new TripUpdatesFeed(
            httpClient: new MockHttpClient(),
            clock: new MockClock(self::NOW, date_default_timezone_get()),
            url: 'https://gtfs-rt.test/trip-updates',
            maxAge: 300,
        );
    }
}
