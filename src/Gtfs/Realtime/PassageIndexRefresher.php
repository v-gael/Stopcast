<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use App\Monitoring\HealthcheckPinger;
use App\Monitoring\RealtimeFeedFailureLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Rafraîchit l'index des passages toutes les GTFS_REALTIME_REFRESH_INTERVAL
 * secondes, dans le planning realtime, que seul realtime-worker consomme :
 * l'import GTFS et l'envoi des emails, traités par worker, ne le retardent
 * jamais. Seul ce service télécharge le flux ; les écrans lisent l'index
 * (NextPassageFinder).
 *
 * L'index garde jusqu'à PASSAGES_PER_LIMIT fois GTFS_REALTIME_NEXT_PASSAGES_LIMIT
 * passages par quai : la limite reste remplie quand les premiers sont partis.
 *
 * Seul un flux qui se décode remplace l'index ; une panne le laisse en place
 * et est journalisée (RealtimeFeedFailureLogger), comme un flux périmé. Un
 * index frais est signalé à Healthchecks.io (HEALTHCHECKS_REALTIME_URL), au
 * plus une fois par minute. L'heure du dernier ping est gardée en mémoire : un
 * redémarrage du worker coûte au pire un ping de plus.
 */
#[AsPeriodicTask(frequency: '%env(int:GTFS_REALTIME_REFRESH_INTERVAL)%', schedule: 'realtime')]
final class PassageIndexRefresher
{
    public const int PASSAGES_PER_LIMIT = 3;

    private const int PING_INTERVAL = 60;

    private ?int $lastPingAt = null;

    public function __construct(
        private readonly PassageIndexBuilder $builder,
        private readonly PassageIndexStore $store,
        private readonly TripUpdatesFeed $tripUpdatesFeed,
        private readonly RealtimeFeedFailureLogger $feedFailureLogger,
        private readonly HealthcheckPinger $pinger,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:GTFS_REALTIME_NEXT_PASSAGES_LIMIT')]
        private readonly int $limit,
        #[Autowire(env: 'HEALTHCHECKS_REALTIME_URL')]
        private readonly string $healthcheckUrl,
    ) {
    }

    public function __invoke(): void
    {
        try {
            $index = $this->builder->build();
        } catch (HttpClientExceptionInterface|InvalidGtfsRealtimeFeedException $failure) {
            $this->feedFailureLogger->log($failure);

            return;
        }

        $this->store->save($index->truncated(self::PASSAGES_PER_LIMIT * $this->limit));

        try {
            $this->tripUpdatesFeed->assertFresh($index->feedTimestamp);
        } catch (InvalidGtfsRealtimeFeedException $invalidGtfsRealtimeFeedException) {
            $this->feedFailureLogger->log($invalidGtfsRealtimeFeedException);

            return;
        }

        $this->pingAtMostEveryMinute();
    }

    private function pingAtMostEveryMinute(): void
    {
        $now = $this->clock->now()->getTimestamp();

        if (null !== $this->lastPingAt && $now - $this->lastPingAt < self::PING_INTERVAL) {
            return;
        }

        $this->lastPingAt = $now;
        $this->pinger->ping($this->healthcheckUrl);
    }
}
