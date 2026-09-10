<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DisplayConfiguration>
 */
class DisplayConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DisplayConfiguration::class);
    }

    /**
     * Les configurations actives d'un dispositif dans l'ordre de rotation.
     *
     * @return DisplayConfiguration[]
     */
    public function findActiveByDeviceOrderedByPosition(Device $device): array
    {
        /** @var DisplayConfiguration[] $result */
        $result = $this->createQueryBuilder('dc')
            ->andWhere('dc.device = :device')
            ->andWhere('dc.active = true')
            ->setParameter('device', $device)
            ->orderBy('dc.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
