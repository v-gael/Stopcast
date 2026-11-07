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
     * Un corps décodé et frais.
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible ou périmé
     */
    public function parse(string $raw): FeedMessage
    {
        $feed = $this->decode($raw);
        $this->assertFresh($feed);

        return $feed;
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
     * @throws InvalidGtfsRealtimeFeedException flux périmé
     */
    public function assertFresh(FeedMessage $feed): void
    {
        $age = $this->ageOf($feed);

        if (!$age->fresh) {
            throw InvalidGtfsRealtimeFeedException::stale($this->url, $age->seconds, $this->maxAge);
        }
    }

    public function ageOf(FeedMessage $feed): FeedAge
    {
        $timestamp = (int) ($feed->getHeader()?->getTimestamp() ?? 0);

        if (0 === $timestamp) {
            return new FeedAge(publishedAt: null, seconds: 0, fresh: true);
        }

        $now = $this->clock->now();
        $seconds = max(0, $now->getTimestamp() - $timestamp);

        return new FeedAge(publishedAt: $now->setTimestamp($timestamp), seconds: $seconds, fresh: $seconds <= $this->maxAge);
    }

    /**
     * Passages à venir lus dans le flux, avant tout croisement avec la base :
     * ni trajet supprimé du service, ni arrêt sauté, ni passage dont l'heure
     * est passée. Un même trajet ne compte qu'un passage par quai.
     *
     * @param array<string, mixed>|null $stopIds quais demandés, en clés ; null pour tous
     *
     * @return list<array{stopId: string, routeId: string, tripId: string, expectedAt: \DateTimeImmutable}>
     */
    public function upcomingStopTimes(FeedMessage $feed, ?array $stopIds = null): array
    {
        $now = $this->clock->now();
        $stopTimes = [];

        foreach ($feed->getEntity() as $entity) {
            $tripUpdate = $entity->getTripUpdate();

            if (null === $tripUpdate) {
                continue;
            }

            // Trajet supprimé du service : aucun de ses passages n'est à afficher.
            if (\in_array($tripUpdate->getTrip()?->getScheduleRelationship(), [TripScheduleRelationship::CANCELED, TripScheduleRelationship::DELETED], true)) {
                continue;
            }

            $routeId = $tripUpdate->getTrip()?->getRouteId() ?? '';

            if ('' === $routeId) {
                continue;
            }

            $tripId = $tripUpdate->getTrip()?->getTripId() ?? '';

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
                    'routeId' => $routeId,
                    'tripId' => $tripId,
                    'expectedAt' => $expectedAt,
                ];
                $settledStopIds[$stopTimeStopId] = true;
            }
        }

        return $stopTimes;
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
