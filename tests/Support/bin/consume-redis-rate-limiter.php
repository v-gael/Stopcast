<?php

declare(strict_types=1);

/*
 * Consomme un jeton d'un rate limiter sliding_window sur Redis, assemblé comme
 * ceux de config/packages/rate_limiter.yaml hors test : compteurs dans un pool
 * cache.adapter.redis (cache.yaml), verrou du store que framework.lock crée
 * depuis REDIS_URL (lock.yaml). Attend l'instant donné pour partir en même
 * temps que les autres processus, puis écrit 1 (accepté) ou 0 (refusé).
 *
 * Lancé en parallèle par RedisRateLimiterBurstTest :
 *   REDIS_URL=… php consume-redis-rate-limiter.php <clé> <limite> <instant>
 */

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\StoreFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var array{string, string, string, string} $arguments */
$arguments = $_SERVER['argv'];
[, $key, $limit, $startAt] = $arguments;
$dsn = (string) getenv('REDIS_URL');

$factory = new RateLimiterFactory(
    ['id' => 'redis_burst', 'policy' => 'sliding_window', 'limit' => (int) $limit, 'interval' => '1 minute'],
    new CacheStorage(new RedisAdapter(RedisAdapter::createConnection($dsn), 'rate_limiter_burst')),
    new LockFactory(StoreFactory::createStore($dsn)),
);

time_sleep_until((float) $startAt);

echo $factory->create($key)->consume()->isAccepted() ? '1' : '0';
