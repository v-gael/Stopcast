<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Planning des tâches de fond, consommé par le worker (transport
 * scheduler_default). Les tâches y entrent par #[AsCronTask] sur leur
 * commande : PurgeCommand, GtfsImportCommand et MailCheckCommand.
 *
 * L'état du planning est gardé en cache : une exécution manquée (worker
 * arrêté, redéploiement) est rattrapée au redémarrage, la dernière seulement.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
