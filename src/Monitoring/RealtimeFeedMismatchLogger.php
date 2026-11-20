<?php

declare(strict_types=1);

namespace App\Monitoring;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signale un flux temps réel lisible dont aucun passage ne tombe sur un quai
 * connu : ses identifiants ne correspondent plus à ceux du GTFS importé, et
 * tous les écrans restent vides sans que le flux soit en panne. Comme
 * RealtimeFeedFailureLogger : un warning à chaque rafraîchissement de l'index,
 * et un error (remonté à Sentry) au plus une fois par quart d'heure, l'heure du
 * dernier gardée dans le pool realtime.cache.
 */
final readonly class RealtimeFeedMismatchLogger
{
    private const string MESSAGE = 'GTFS-RT feed matches no known quay.';

    private const string LAST_ERROR_KEY = 'feed_mismatch_last_error';

    private const int ERROR_INTERVAL = 15 * 60;

    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(service: 'realtime.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $sampleStopIds quelques stop_id du flux, pour le contexte
     */
    public function log(int $passageCount, array $sampleStopIds): void
    {
        $context = ['passages' => $passageCount, 'sample_stop_ids' => $sampleStopIds];
        $this->logger->warning(self::MESSAGE, $context);

        $now = $this->clock->now()->getTimestamp();
        $lastError = $this->cache->getItem(self::LAST_ERROR_KEY);
        $lastErrorAt = $lastError->get();

        if (\is_int($lastErrorAt) && $now - $lastErrorAt < self::ERROR_INTERVAL) {
            return;
        }

        $this->cache->save($lastError->set($now)->expiresAfter(self::ERROR_INTERVAL));
        $this->logger->error(self::MESSAGE, $context);
    }
}
