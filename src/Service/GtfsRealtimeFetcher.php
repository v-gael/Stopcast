<?php

declare(strict_types=1);

namespace App\Service;

use Google\Transit\Realtime\FeedMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge et parse le flux GTFS-RT TripUpdates.
 *
 * Le résultat brut est mis en cache un court instant (GTFS_REALTIME_CACHE_TTL)
 * de façon PARTAGÉE entre tous les devices : peu importe combien de Raspberry Pi
 * interrogent l'API en même temps, le flux amont n'est téléchargé/parsé
 * qu'une seule fois par fenêtre de cache.
 */
final readonly class GtfsRealtimeFetcher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private string $tripUpdatesUrl,
        private int $cacheTtl,
    ) {
    }

    public function fetchTripUpdates(): FeedMessage
    {
        $item = $this->cache->getItem('gtfs_rt.trip_updates');

        if ($item->isHit()) {
            /** @var string $raw */
            $raw = $item->get();
        } else {
            $raw = $this->httpClient->request('GET', $this->tripUpdatesUrl)->getContent();

            $item->set($raw);
            $item->expiresAfter($this->cacheTtl);
            $this->cache->save($item);
        }

        $feed = new FeedMessage();
        $feed->mergeFromString($raw);

        return $feed;
    }
}
