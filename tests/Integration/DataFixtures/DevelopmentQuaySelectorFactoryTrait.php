<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\DevelopmentQuaySelector;
use App\Repository\QuayRepository;
use App\Tests\Support\RealtimeFeedTrait;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sélecteur branché sur un flux temps réel simulé, sans appel réseau.
 */
trait DevelopmentQuaySelectorFactoryTrait
{
    use RealtimeFeedTrait;

    /**
     * @param array<string, list<string>> $stopIdsByTripId trajets du flux (route R1), avec les quais desservis dans 5 minutes
     */
    private function selectorWithFeed(array $stopIdsByTripId): DevelopmentQuaySelector
    {
        $entities = [];
        foreach ($stopIdsByTripId as $tripId => $stopIds) {
            $entities[] = new FeedEntity()
                ->setTripUpdate(new TripUpdate()
                    ->setTrip(new TripDescriptor()->setTripId((string) $tripId)->setRouteId('R1'))
                    ->setStopTimeUpdate(array_map(
                        static fn (string $stopId): StopTimeUpdate => new StopTimeUpdate()
                            ->setStopId($stopId)
                            ->setDeparture(new StopTimeEvent()->setTime(self::at(5))),
                        $stopIds,
                    )));
        }

        return $this->selector(self::feedResponse($entities));
    }

    private function selectorWithUnavailableFeed(): DevelopmentQuaySelector
    {
        return $this->selector(new MockResponse('', ['http_code' => 503]));
    }

    private function selector(MockResponse $response): DevelopmentQuaySelector
    {
        $quayRepository = self::getContainer()->get(QuayRepository::class);
        self::assertInstanceOf(QuayRepository::class, $quayRepository);

        return new DevelopmentQuaySelector($this->nextPassageFinder(new MockHttpClient($response)), $quayRepository);
    }
}
