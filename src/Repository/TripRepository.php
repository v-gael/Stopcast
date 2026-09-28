<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /**
     * Destinations des trajets connus parmi ceux demandés, en une seule requête.
     * Les trajets inconnus sont absents du résultat.
     *
     * @param string[] $gtfsTripIds
     *
     * @return array<string, string|null> trip_id => trip_headsign
     */
    public function findHeadsignsByGtfsTripIds(array $gtfsTripIds): array
    {
        if ([] === $gtfsTripIds) {
            return [];
        }

        /** @var list<array{gtfsTripId: string, headsign: string|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.gtfsTripId', 't.headsign')
            ->where('t.gtfsTripId IN (:gtfsTripIds)')
            ->setParameter('gtfsTripIds', array_values(array_unique($gtfsTripIds)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'headsign', 'gtfsTripId');
    }
}
