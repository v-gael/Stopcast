<?php

declare(strict_types=1);

namespace App\Repository;

use App\Account\EmailNormalizer;
use App\Entity\AccessRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    public function findOneByEmail(string $email): ?AccessRequest
    {
        return $this->findOneBy(['email' => EmailNormalizer::normalize($email)]);
    }

    /**
     * @return list<AccessRequest>
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Supprime la demande de cette adresse, s'il y en a une, sans flush :
     * elle part avec l'écriture qui l'a rendue inutile.
     */
    public function removeByEmail(string $email): void
    {
        $accessRequest = $this->findOneByEmail($email);
        if ($accessRequest instanceof AccessRequest) {
            $this->getEntityManager()->remove($accessRequest);
        }
    }

    /**
     * @return int le nombre de demandes supprimées
     */
    public function removeRequestedBefore(\DateTimeImmutable $requestedBefore): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.requestedAt < :requestedBefore')
            ->setParameter('requestedBefore', $requestedBefore)
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
