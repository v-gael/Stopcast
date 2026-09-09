<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /**
     * Utilisé par l'authentification du Raspberry Pi sur l'API :
     * ne retourne un dispositif que s'il est actif
     */
    public function findOneActiveByToken(string $token): ?Device
    {
        return $this->findOneBy(['token' => $token, 'active' => true]);
    }
}
