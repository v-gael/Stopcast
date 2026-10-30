<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Nombre de requêtes SQL jouées par un appel, unité de travail vidée : pour
 * vérifier qu'un chargement est groupé.
 */
trait QueryCountTrait
{
    private function countQueries(callable $callback): int
    {
        $container = self::getContainer();
        $debugDataHolder = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debugDataHolder);
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->clear();
        $debugDataHolder->reset();
        $callback();

        $queries = $debugDataHolder->getData()['default'] ?? [];
        self::assertIsArray($queries);

        return \count($queries);
    }
}
