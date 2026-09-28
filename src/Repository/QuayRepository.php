<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Quay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quay>
 */
class QuayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quay::class);
    }

    public function findOneByGtfsStopId(string $gtfsStopId): ?Quay
    {
        return $this->findOneBy(['gtfsStopId' => $gtfsStopId]);
    }

    /**
     * Tous les quais actifs d'un même arrêt physique.
     *
     * @return Quay[]
     */
    public function findActiveByParentStopId(string $parentStopId): array
    {
        /** @var Quay[] $result */
        $result = $this->createQueryBuilder('q')
            ->andWhere('q.parentStopId = :parentStopId')
            ->andWhere('q.active = true')
            ->setParameter('parentStopId', $parentStopId)
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Distincts noms d'arrêts physiques actifs.
     *
     * @return array<int, array{parentStopId: string, name: string}>
     */
    public function findActiveParentStops(): array
    {
        /** @var array<int, array{parentStopId: string, name: string}> $result */
        $result = $this->createQueryBuilder('q')
            ->select('DISTINCT q.parentStopId AS parentStopId', 'q.name AS name')
            ->andWhere('q.active = true')
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $result;
    }

    /**
     * @return Quay[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true]);
    }

    /**
     * @param string[] $gtfsStopIds
     *
     * @return Quay[]
     */
    public function findActiveByGtfsStopIds(array $gtfsStopIds): array
    {
        if ([] === $gtfsStopIds) {
            return [];
        }

        return $this->findBy(['gtfsStopId' => array_values($gtfsStopIds), 'active' => true]);
    }

    /**
     * Quais actifs desservis par le plus de lignes, départagés par stop_id.
     *
     * @param string[] $excludedGtfsStopIds
     *
     * @return Quay[]
     */
    public function findActiveMostServed(int $limit, array $excludedGtfsStopIds = []): array
    {
        $queryBuilder = $this->createQueryBuilder('q')
            ->leftJoin('q.routes', 'r')
            ->andWhere('q.active = true')
            ->groupBy('q.id')
            ->orderBy('COUNT(r.id)', 'DESC')
            ->addOrderBy('q.gtfsStopId', 'ASC')
            ->setMaxResults($limit);

        if ([] !== $excludedGtfsStopIds) {
            $queryBuilder
                ->andWhere('q.gtfsStopId NOT IN (:excludedGtfsStopIds)')
                ->setParameter('excludedGtfsStopIds', array_values($excludedGtfsStopIds));
        }

        /** @var Quay[] $result */
        $result = $queryBuilder->getQuery()->getResult();

        return $result;
    }
}
