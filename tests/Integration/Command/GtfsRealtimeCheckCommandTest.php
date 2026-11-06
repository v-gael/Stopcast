<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\Support\RealtimeFeedMessageTrait;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GtfsRealtimeCheckCommandTest extends KernelTestCase
{
    use RealtimeFeedMessageTrait;

    public function testAFreshFeedIsReportedOnOneLine(): void
    {
        $tester = $this->command(self::feedResponse([
            $this->tripUpdate('T1', [-2, 3, 8]),
            $this->tripUpdate('T2', [12]),
        ], self::at(0) - 32));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringEndsWith(
            " header 07:59:28, âge 32 s, 2 entités, 3 passages à venir : frais\n",
            $tester->getDisplay(),
        );
        self::assertStringStartsWith('08:00 ', $tester->getDisplay());
    }

    public function testCancelledTripsAreNotCountedAsUpcoming(): void
    {
        $cancelled = $this->tripUpdate('T1', [5]);
        $cancelled->getTripUpdate()?->getTrip()?->setScheduleRelationship(ScheduleRelationship::CANCELED);

        $tester = $this->command(self::feedResponse([$cancelled, $this->tripUpdate('T2', [5])]));

        $tester->execute([]);

        self::assertStringContainsString('2 entités, 1 passages à venir', $tester->getDisplay());
    }

    public function testAStaleFeedFailsWithItsCounts(): void
    {
        $tester = $this->command(self::feedResponse([$this->tripUpdate('T1', [5])], self::at(-30)));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringEndsWith(
            " header 07:30:00, âge 1800 s, 1 entités, 1 passages à venir : périmé\n",
            $tester->getDisplay(),
        );
    }

    public function testAFeedWithoutTimestampIsFresh(): void
    {
        $tester = $this->command(self::feedResponse([], 0));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(' header sans timestamp, âge 0 s, ', $tester->getDisplay());
    }

    #[DataProvider('failures')]
    public function testAnUnavailableFeedFailsWithItsCause(MockResponse $response, string $cause): void
    {
        $tester = $this->command($response);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(' : '.$cause, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function failures(): iterable
    {
        yield 'erreur HTTP' => [new MockResponse('', ['http_code' => 503]), 'injoignable (HTTP 503)'];
        yield 'serveur injoignable' => [new MockResponse('', ['error' => 'Could not resolve host']), 'injoignable (Could not resolve host'];
        yield 'page HTML' => [new MockResponse('<html>Maintenance</html>'), 'illisible (Flux GTFS-RT illisible'];
    }

    /**
     * @param list<int> $minutes départs du trajet, en minutes depuis l'heure fixée, chacun à un quai différent
     */
    private function tripUpdate(string $tripId, array $minutes): FeedEntity
    {
        return new FeedEntity()->setTripUpdate(new TripUpdate()
            ->setTrip(new TripDescriptor()->setTripId($tripId)->setRouteId('R1'))
            ->setStopTimeUpdate(array_map(
                static fn (int $i, int $minute): StopTimeUpdate => new StopTimeUpdate()
                    ->setStopId('S'.$i)
                    ->setDeparture(new StopTimeEvent()->setTime(self::at($minute))),
                array_keys($minutes),
                $minutes,
            )));
    }

    /**
     * La commande telle que la console la lance, sur un flux et une horloge
     * simulés ; le seuil de péremption est celui de .env (300 s).
     */
    private function command(MockResponse $response): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('http_client', new MockHttpClient($response));
        self::getContainer()->set('clock', new MockClock(self::NOW, date_default_timezone_get()));

        return new CommandTester(new Application($kernel)->find('app:gtfs:realtime:check'));
    }
}
