<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'index des passages, en un seul bloc dans le pool realtime.cache (Redis),
 * partagé entre realtime-worker, qui l'écrit, et app, qui le lit. Sans
 * expiration : un index ancien reste lisible, et son lecteur en vérifie l'âge.
 */
final readonly class PassageIndexStore
{
    private const string KEY = 'passage_index';

    public function __construct(
        #[Autowire(service: 'realtime.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function save(PassageIndex $index): void
    {
        $this->cache->save($this->cache->getItem(self::KEY)->set($index));
    }

    /**
     * Le dernier index enregistré, null s'il n'y en a pas (premier
     * déploiement, Redis vidé).
     */
    public function load(): ?PassageIndex
    {
        $index = $this->cache->getItem(self::KEY)->get();

        return $index instanceof PassageIndex ? $index : null;
    }
}
