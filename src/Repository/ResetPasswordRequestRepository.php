<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    /**
     * @param User $user
     */
    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    /**
     * Supprime les demandes expirées des comptes actifs. Celles d'un compte en
     * attente restent : elles datent sa dernière invitation.
     *
     * @return int le nombre de demandes supprimées
     */
    public function removeExpiredRequestsOfActiveUsers(\DateTimeImmutable $now): int
    {
        $activeUsers = $this->getEntityManager()->createQueryBuilder()
            ->select('u.id')
            ->from(User::class, 'u')
            ->where('u.password IS NOT NULL');

        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.expiresAt <= :now')
            ->andWhere(\sprintf('r.user IN (%s)', $activeUsers->getDQL()))
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
