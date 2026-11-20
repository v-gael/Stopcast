<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Flux des mises à jour de trajets (GTFS_REALTIME_TRIP_UPDATES_URL),
 * téléchargé sans cache et décodé. Un flux est frais si son header.timestamp
 * a au plus GTFS_REALTIME_MAX_AGE secondes ; un timestamp absent (0) ou dans
 * le futur compte comme frais.
 */
final readonly class TripUpdatesFeed
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        #[Autowire(env: 'GTFS_REALTIME_TRIP_UPDATES_URL')]
        private string $url,
        #[Autowire(env: 'int:GTFS_REALTIME_MAX_AGE')]
        private int $maxAge,
    ) {
    }

    /**
     * Le corps brut du flux, tel que le producteur le sert.
     *
     * @throws HttpClientExceptionInterface téléchargement impossible
     */
    public function downloadRaw(): string
    {
        return $this->httpClient->request('GET', $this->url)->getContent();
    }

    /**
     * Un corps décodé, sans vérifier son âge.
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible
     */
    public function decode(string $raw): FeedMessage
    {
        $feed = new FeedMessage();

        try {
            $feed->mergeFromString($raw);
        } catch (\Exception $exception) {
            throw InvalidGtfsRealtimeFeedException::undecodable($this->url, $exception);
        }

        // Le header est obligatoire en GTFS-RT ; un corps vide se décode
        // sans erreur en un flux sans header.
        if (!$feed->hasHeader()) {
            throw InvalidGtfsRealtimeFeedException::missingHeader($this->url);
        }

        return $feed;
    }

    /**
     * @param int $headerTimestamp header.timestamp d'un flux, 0 s'il est absent
     *
     * @throws InvalidGtfsRealtimeFeedException flux périmé
     */
    public function assertFresh(int $headerTimestamp): void
    {
        $age = $this->ageAt($headerTimestamp);

        if (!$age->fresh) {
            throw InvalidGtfsRealtimeFeedException::stale($this->url, $age->seconds, $this->maxAge);
        }
    }

    /**
     * L'index est-il encore frais ? Mesuré sur le timestamp de son flux, ou
     * à défaut sur l'heure de sa construction (PassageIndex::publishedAt).
     *
     * @throws InvalidGtfsRealtimeFeedException index périmé
     */
    public function assertIndexFresh(PassageIndex $index): void
    {
        $this->assertFresh($index->publishedAt());
    }

    public function ageOf(FeedMessage $feed): FeedAge
    {
        return $this->ageAt(self::timestampOf($feed));
    }

    /**
     * @param int $headerTimestamp header.timestamp d'un flux, 0 s'il est absent
     */
    public function ageAt(int $headerTimestamp): FeedAge
    {
        if (0 === $headerTimestamp) {
            return new FeedAge(publishedAt: null, seconds: 0, fresh: true);
        }

        $now = $this->clock->now();
        $seconds = max(0, $now->getTimestamp() - $headerTimestamp);

        return new FeedAge(publishedAt: $now->setTimestamp($headerTimestamp), seconds: $seconds, fresh: $seconds <= $this->maxAge);
    }

    /**
     * Passages à venir lus dans le flux, avant tout croisement avec la base :
     * ni trajet supprimé du service, ni arrêt sauté, ni passage dont l'heure
     * est passée. Un même trajet ne compte qu'un passage par quai. La
     * stop_sequence du passage est null si le flux ne la donne pas.
     *
     * @param array<string, mixed>|null $stopIds quais demandés, en clés ; null pour tous
     *
     * @return list<array{stopId: string, stopSequence: int|null, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}>
     */
    public function upcomingStopTimes(FeedMessage $feed, ?array $stopIds = null): array
    {
        $now = $this->clock->now();
        $stopTimes = [];

        foreach ($feed->getEntity() as $entity) {
            $tripUpdate = $entity->getTripUpdate();
            $trip = $tripUpdate?->getTrip();

            // Sans TripUpdate ni TripDescriptor, pas de ligne à afficher.
            if (null === $tripUpdate || null === $trip) {
                continue;
            }

            // Trajet supprimé du service : aucun de ses passages n'est à afficher.
            if (\in_array($trip->getScheduleRelationship(), [TripScheduleRelationship::CANCELED, TripScheduleRelationship::DELETED], true)) {
                continue;
            }

            $routeId = $trip->getRouteId();

            if ('' === $routeId) {
                continue;
            }

            $tripId = $trip->getTripId();

            // Un même trip ne dessert un quai donné qu'une fois dans sa course :
            // un quai retenu ou sauté n'est plus examiné pour ce trip.
            $settledStopIds = [];

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                $stopTimeStopId = $stopTimeUpdate->getStopId();

                if ((null !== $stopIds && !\array_key_exists($stopTimeStopId, $stopIds)) || isset($settledStopIds[$stopTimeStopId])) {
                    continue;
                }

                // Arrêt sauté : le bus passe sans s'arrêter au quai.
                if (StopScheduleRelationship::SKIPPED === $stopTimeUpdate->getScheduleRelationship()) {
                    $settledStopIds[$stopTimeStopId] = true;

                    continue;
                }

                // Le départ peut ne porter qu'un delay (heure à 0) : on se rabat
                // alors sur l'arrivée.
                $time = $this->timeOf($stopTimeUpdate->getDeparture()) ?: $this->timeOf($stopTimeUpdate->getArrival());

                if (0 === $time) {
                    continue;
                }

                $expectedAt = $now->setTimestamp($time);

                if ($expectedAt < $now) {
                    continue;
                }

                $stopTimes[] = [
                    'stopId' => $stopTimeStopId,
                    'stopSequence' => $stopTimeUpdate->hasStopSequence() ? $stopTimeUpdate->getStopSequence() : null,
                    'routeId' => $routeId,
                    'tripId' => $tripId,
                    'expectedAt' => $expectedAt,
                ];
                $settledStopIds[$stopTimeStopId] = true;
            }
        }

        return $stopTimes;
    }

    /**
     * header.timestamp du flux, 0 s'il est absent.
     */
    public static function timestampOf(FeedMessage $feed): int
    {
        return (int) ($feed->getHeader()?->getTimestamp() ?? 0);
    }

    public function url(): string
    {
        return $this->url;
    }

    private function timeOf(?StopTimeEvent $event): int
    {
        return (int) ($event?->getTime() ?? 0);
    }
}
