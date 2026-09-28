<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidGtfsRealtimeFeedException;
use Google\Transit\Realtime\FeedMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge et parse le flux GTFS-RT TripUpdates.
 *
 * Le résultat brut est mis en cache un court instant (GTFS_REALTIME_CACHE_TTL)
 * de façon PARTAGÉE entre tous les devices : peu importe combien de Raspberry Pi
 * interrogent l'API en même temps, le flux amont n'est téléchargé/parsé
 * qu'une seule fois par fenêtre de cache. Seule une réponse qui se parse en un
 * flux valide est mise en cache : sinon, chaque appel échouerait jusqu'à
 * l'expiration, alors que la requête suivante aurait pu réussir.
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

            return $this->parse($raw);
        }

        $raw = $this->httpClient->request('GET', $this->tripUpdatesUrl)->getContent();
        $feed = $this->parse($raw);

        $item->set($raw);
        $item->expiresAfter($this->cacheTtl);

        $this->cache->save($item);

        return $feed;
    }

    /**
     * @throws InvalidGtfsRealtimeFeedException
     */
    private function parse(string $raw): FeedMessage
    {
        $feed = new FeedMessage();

        try {
            $feed->mergeFromString($raw);
        } catch (\Exception $exception) {
            throw InvalidGtfsRealtimeFeedException::undecodable($this->tripUpdatesUrl, $exception);
        }

        // Le header est obligatoire en GTFS-RT ; un corps vide se décode
        // sans erreur en un flux sans header.
        if (!$feed->hasHeader()) {
            throw InvalidGtfsRealtimeFeedException::missingHeader($this->tripUpdatesUrl);
        }

        return $feed;
    }
}
