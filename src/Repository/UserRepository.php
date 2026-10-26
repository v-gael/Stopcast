<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Tous les comptes, du plus récent au plus ancien, avec le nombre de leurs
     * dispositifs compté dans la même requête.
     *
     * @return list<array{user: User, deviceCount: int}>
     */
    public function findAllWithDeviceCount(): array
    {
        /** @var list<array{user: User, deviceCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u AS user', 'COUNT(d.id) AS deviceCount')
            ->leftJoin(Device::class, 'd', 'WITH', 'd.owner = u')
            ->groupBy('u.id')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['user' => $row['user'], 'deviceCount' => (int) $row['deviceCount']], $rows);
    }

    /**
     * Rehache le mot de passe à la connexion quand l'algorithme a changé.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
