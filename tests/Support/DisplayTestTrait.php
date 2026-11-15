<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Device;
use App\Gtfs\Realtime\PassageIndexRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Gestes des tests de la page d'affichage (écran et aperçu), faits entre
 * deux requêtes du client de test.
 *
 * L'index des passages est sur disque en test (cache.yaml) : il survit au
 * redémarrage du kernel entre deux requêtes, et d'un test à l'autre. Un test
 * qui lit l'écran commence donc par le rafraîchir ou le retirer.
 */
trait DisplayTestTrait
{
    /**
     * Rafraîchit l'index comme realtime-worker, sur le flux du client HTTP du
     * conteneur (RealtimeFeedMock, sauf s'il a été remplacé), à l'heure de
     * son horloge.
     */
    private function refreshPassageIndex(): void
    {
        $refresher = self::getContainer()->get(PassageIndexRefresher::class);
        self::assertInstanceOf(PassageIndexRefresher::class, $refresher);

        $refresher();
    }

    /**
     * Retire l'index, comme avant le premier rafraîchissement : l'écran
     * affiche « flux indisponible ».
     */
    private function removePassageIndex(): void
    {
        $pool = self::getContainer()->get('realtime.cache');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $pool->clear();
    }

    private function disableDevice(Device $device): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $stored = $entityManager->find(Device::class, $device->getId());
        self::assertInstanceOf(Device::class, $stored);
        $stored->setActive(false);
        $entityManager->flush();
        $entityManager->clear();
    }
}
