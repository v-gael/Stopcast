<?php

declare(strict_types=1);

namespace App\Monitoring;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signale à l'exploitation que le flux temps réel est injoignable, illisible
 * ou périmé : un warning à chaque échec, et un error (remonté à Sentry) au
 * plus une fois par quart d'heure, pour ne pas épuiser son quota quand tous
 * les écrans échouent à chaque rafraîchissement.
 *
 * L'heure du dernier error est partagée entre les requêtes par le pool du
 * flux ; perdue (cache vidé, redéploiement), elle coûte au pire un error de
 * plus.
 */
final readonly class RealtimeFeedFailureLogger
{
    private const string MESSAGE = 'GTFS-RT feed unavailable.';

    private const string LAST_ERROR_KEY = 'feed_failure_last_error';

    private const int ERROR_INTERVAL = 15 * 60;

    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(service: 'gtfs_realtime.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Le message de l'exception, qui nomme l'URL du flux, part en contexte ;
     * l'exception n'est pas jointe, pour que Sentry regroupe tous les échecs
     * en une seule erreur.
     */
    public function log(\Throwable $failure): void
    {
        $context = ['cause' => $failure->getMessage(), 'exception_class' => $failure::class];
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
