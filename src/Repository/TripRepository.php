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
     * Destination et terminus (quai et séquence) des trajets connus parmi ceux
     * demandés, en une seule requête. Les trajets inconnus sont absents du
     * résultat.
     *
     * @param string[] $gtfsTripIds
     *
     * @return array<string, array{headsign: string|null, terminusStopId: string|null, terminusStopSequence: int|null}> indexé par trip_id
     */
    public function findHeadsignsAndTerminiByGtfsTripIds(array $gtfsTripIds): array
    {
        if ([] === $gtfsTripIds) {
            return [];
        }

        /** @var list<array{gtfsTripId: string, headsign: string|null, terminusStopId: string|null, terminusStopSequence: int|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.gtfsTripId', 't.headsign', 't.terminusStopId', 't.terminusStopSequence')
            ->where('t.gtfsTripId IN (:gtfsTripIds)')
            ->setParameter('gtfsTripIds', array_values(array_unique($gtfsTripIds)))
            ->getQuery()
            ->getArrayResult();

        $trips = [];
        foreach ($rows as $row) {
            $trips[$row['gtfsTripId']] = [
                'headsign' => $row['headsign'],
                'terminusStopId' => $row['terminusStopId'],
                'terminusStopSequence' => $row['terminusStopSequence'],
            ];
        }

        return $trips;
    }

    /**
     * Destination et terminus des courses demandées par la fin de leur
     * trip_id, en une seule requête : le flux de TCAT écrit `C2026456` pour les
     * trajets `PS : Lu Ma Je VeC2026456` et `PS : MeC2026456`, un par service.
     * Une course n'est retenue que si tous ses trajets ont la même destination
     * et le même terminus (quai et séquence) ; sinon, comme une course sans
     * trajet, elle est absente du résultat.
     *
     * @param string[] $courseIds lettre C suivie de chiffres, sans caractère spécial de LIKE
     *
     * @return array<string, array{headsign: string|null, terminusStopId: string|null, terminusStopSequence: int|null}> indexé par course
     */
    public function findHeadsignsAndTerminiByCourseIds(array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_filter($courseIds, static fn (string $courseId): bool => 1 === preg_match('/^C\d+$/', $courseId))));

        if ([] === $courseIds) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('t')
            ->select('t.gtfsTripId', 't.headsign', 't.terminusStopId', 't.terminusStopSequence');
        $matches = $queryBuilder->expr()->orX();
        foreach ($courseIds as $position => $courseId) {
            $matches->add($queryBuilder->expr()->like('t.gtfsTripId', ':course'.$position));
            $queryBuilder->setParameter('course'.$position, '%'.$courseId);
        }

        /** @var list<array{gtfsTripId: string, headsign: string|null, terminusStopId: string|null, terminusStopSequence: int|null}> $rows */
        $rows = $queryBuilder->where($matches)->getQuery()->getArrayResult();

        $variantsByCourseId = [];
        foreach ($rows as $row) {
            foreach ($courseIds as $courseId) {
                if (str_ends_with($row['gtfsTripId'], $courseId)) {
                    $variantsByCourseId[$courseId][] = [
                        'headsign' => $row['headsign'],
                        'terminusStopId' => $row['terminusStopId'],
                        'terminusStopSequence' => $row['terminusStopSequence'],
                    ];
                }
            }
        }

        $trips = [];
        foreach ($variantsByCourseId as $courseId => $variants) {
            if (1 === \count(array_unique(array_map(serialize(...), $variants)))) {
                $trips[$courseId] = $variants[0];
            }
        }

        return $trips;
    }
}
