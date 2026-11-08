<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Nombre de requêtes SQL jouées par un appel, unité de travail vidée : pour
 * vérifier qu'un chargement est groupé, ou, avec un début de requête
 * (« UPDATE device »), qu'une écriture a lieu ou non.
 */
trait QueryCountTrait
{
    private function countQueries(callable $callback, ?string $startingWith = null): int
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

        if (null === $startingWith) {
            return \count($queries);
        }

        $count = 0;
        foreach ($queries as $query) {
            self::assertIsArray($query);
            self::assertIsString($query['sql']);
            $count += str_starts_with($query['sql'], $startingWith) ? 1 : 0;
        }

        return $count;
    }
}
