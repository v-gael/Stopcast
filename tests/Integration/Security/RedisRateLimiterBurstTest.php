<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Rafale de consommations simultanées sur un rate limiter, depuis plusieurs
 * processus, comme plusieurs workers FrankenPHP ou plusieurs conteneurs app :
 * le verrou Redis empêche le lire-modifier-écrire du sliding window de laisser
 * passer plus que la limite. Joint le vrai Redis de REDIS_URL (service redis
 * de Compose en dev, service du job en CI) ; le reste de la suite garde ses
 * limiters en mémoire.
 */
final class RedisRateLimiterBurstTest extends TestCase
{
    private const int LIMIT = 5;

    private const int PROCESSES = 20;

    public function testAParallelBurstDoesNotExceedTheLimit(): void
    {
        $redisUrl = $_SERVER['REDIS_URL'];
        self::assertIsString($redisUrl);
        $key = bin2hex(random_bytes(8));
        // Le temps de démarrer tous les processus PHP avant le départ commun.
        $startAt = (string) (microtime(true) + 2.0);

        $processes = [];
        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = new Process(
                [\PHP_BINARY, __DIR__.'/../../Support/bin/consume-redis-rate-limiter.php', $key, (string) self::LIMIT, $startAt],
                env: ['REDIS_URL' => $redisUrl],
            );
            $process->start();
            $processes[] = $process;
        }

        $accepted = 0;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $accepted += (int) $process->getOutput();
        }

        self::assertSame(self::LIMIT, $accepted);
    }
}
