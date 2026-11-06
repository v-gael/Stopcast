<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
     * Le dispositif dont l'écran appairé détient ce secret, actif ou non.
     */
    public function findOneByScreenSecretHash(string $screenSecretHash): ?Device
    {
        return $this->findOneBy(['screenSecretHash' => $screenSecretHash]);
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
    }

    /**
     * Une page des dispositifs d'un compte, du plus récent au plus ancien, avec
     * leurs configurations chargées. « $search » filtre sur le libellé, sans
     * tenir compte de la casse ; le Paginator compte aussi le total filtré.
     *
     * @return Paginator<Device>
     */
    public function findPageByOwner(User $owner, string $search, int $page, int $perPage): Paginator
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('c')
            ->leftJoin('d.displayConfigurations', 'c')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('LOWER(d.label) LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower($search), '%_\\').'%');
        }

        /** @var Paginator<Device> $paginator */
        $paginator = new Paginator($queryBuilder);

        return $paginator;
    }

    /**
     * Dispositifs d'un compte, du plus ancien au plus récent, avec leurs
     * configurations et leurs quais chargés en une seule requête.
     *
     * @return list<Device>
     */
    public function findByOwnerWithConfigurationsAndQuays(User $owner): array
    {
        /** @var list<Device> $devices */
        $devices = $this->createQueryBuilder('d')
            ->addSelect('c', 'q')
            ->leftJoin('d.displayConfigurations', 'c')
            ->leftJoin('c.quay', 'q')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $devices;
    }
}
