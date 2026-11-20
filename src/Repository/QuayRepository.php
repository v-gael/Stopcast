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
     * Les stop_id connus parmi ceux demandés, quais obsolètes compris, en une
     * seule requête. La comparaison est exacte, casse comprise.
     *
     * @param string[] $gtfsStopIds
     *
     * @return array<string, true> indexé par stop_id
     */
    public function findKnownGtfsStopIds(array $gtfsStopIds): array
    {
        if ([] === $gtfsStopIds) {
            return [];
        }

        /** @var list<array{gtfsStopId: string}> $rows */
        $rows = $this->createQueryBuilder('q')
            ->select('q.gtfsStopId')
            ->where('q.gtfsStopId IN (:gtfsStopIds)')
            ->setParameter('gtfsStopIds', array_values(array_unique($gtfsStopIds)))
            ->getQuery()
            ->getArrayResult();

        return array_fill_keys(array_column($rows, 'gtfsStopId'), true);
    }

    /**
     * @return Quay[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['deprecatedAt' => null]);
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

        return $this->findBy(['gtfsStopId' => array_values($gtfsStopIds), 'deprecatedAt' => null]);
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
            ->andWhere('q.deprecatedAt IS NULL')
            ->groupBy('q.id')
            ->orderBy('COUNT(r.id)', \SortDirection::Descending)
            ->addOrderBy('q.gtfsStopId', \SortDirection::Ascending)
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
