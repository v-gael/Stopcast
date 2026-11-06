<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\RouteRepository;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client HTTP par défaut simulé en test (mock_response_factory) : le flux
 * GTFS-RT (GTFS_REALTIME_TRIP_UPDATES_URL) sert, pour chaque ligne en base,
 * deux passages à venir à chacun de ses quais, à l'heure de l'horloge. Toute
 * autre URL répond 404. Sert les tests qui ne remplacent pas http_client,
 * dont les tests navigateur, dont le serveur est un autre processus.
 */
final readonly class RealtimeFeedMock
{
    /**
     * Minutes d'attente des passages servis à chaque quai.
     */
    private const array MINUTES = [3, 12];

    public function __construct(
        private RouteRepository $routeRepository,
        private ClockInterface $clock,
        #[Autowire(env: 'GTFS_REALTIME_TRIP_UPDATES_URL')]
        private string $tripUpdatesUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        if ($url !== $this->tripUpdatesUrl) {
            return new MockResponse('', ['http_code' => 404]);
        }

        $now = $this->clock->now()->getTimestamp();
        $entities = [];
        foreach ($this->routeRepository->findAll() as $route) {
            foreach ($route->getQuays() as $quay) {
                foreach (self::MINUTES as $minutes) {
                    $entities[] = new FeedEntity()
                        ->setId((string) \count($entities))
                        ->setTripUpdate(new TripUpdate()
                            ->setTrip(new TripDescriptor()->setRouteId($route->getGtfsRouteId()))
                            ->setStopTimeUpdate([new StopTimeUpdate()
                                ->setStopId($quay->getGtfsStopId())
                                ->setDeparture(new StopTimeEvent()->setTime($now + $minutes * 60)), ]));
                }
            }
        }

        return new MockResponse(new FeedMessage()
            ->setHeader(new FeedHeader()->setGtfsRealtimeVersion('2.0')->setTimestamp($now))
            ->setEntity($entities)
            ->serializeToString());
    }
}
