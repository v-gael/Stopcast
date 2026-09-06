<?php

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
     * Tous les quais actifs d'un même arrêt physique
     *
     * @return Quay[]
     */
    public function findActiveByParentStopId(string $parentStopId): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.parentStopId = :parentStopId')
            ->andWhere('q.active = true')
            ->setParameter('parentStopId', $parentStopId)
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Distincts noms d'arrêts physiques actifs
     *
     * @return array<int, array{parentStopId: string, name: string}>
     */
    public function findActiveParentStops(): array
    {
        return $this->createQueryBuilder('q')
            ->select('DISTINCT q.parentStopId AS parentStopId', 'q.name AS name')
            ->andWhere('q.active = true')
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return Quay[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true]);
    }
}
