<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\User;
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

    /**
     * Dispositifs d'un compte, du plus récent au plus ancien, avec leurs
     * configurations chargées pour les compter sans requête de plus.
     *
     * @return list<Device>
     */
    public function findByOwner(User $owner): array
    {
        /** @var list<Device> $devices */
        $devices = $this->createQueryBuilder('d')
            ->addSelect('c')
            ->leftJoin('d.displayConfigurations', 'c')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $devices;
    }
}
