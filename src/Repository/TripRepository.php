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
     * Destination et terminus des trajets connus parmi ceux demandés, en une
     * seule requête. Les trajets inconnus sont absents du résultat.
     *
     * @param string[] $gtfsTripIds
     *
     * @return array<string, array{headsign: string|null, terminusStopId: string|null}> indexé par trip_id
     */
    public function findHeadsignsAndTerminiByGtfsTripIds(array $gtfsTripIds): array
    {
        if ([] === $gtfsTripIds) {
            return [];
        }

        /** @var list<array{gtfsTripId: string, headsign: string|null, terminusStopId: string|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.gtfsTripId', 't.headsign', 't.terminusStopId')
            ->where('t.gtfsTripId IN (:gtfsTripIds)')
            ->setParameter('gtfsTripIds', array_values(array_unique($gtfsTripIds)))
            ->getQuery()
            ->getArrayResult();

        $trips = [];
        foreach ($rows as $row) {
            $trips[$row['gtfsTripId']] = ['headsign' => $row['headsign'], 'terminusStopId' => $row['terminusStopId']];
        }

        return $trips;
    }
}
