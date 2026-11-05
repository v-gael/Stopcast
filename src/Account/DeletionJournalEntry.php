<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;

/**
 * Une suppression de compte du journal : l'identifiant et la date de création
 * désignent le compte sans donnée personnelle.
 */
final readonly class DeletionJournalEntry
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $deletedAt,
    ) {
    }

    /**
     * Les dates sont comparées comme des instants : le fuseau de l'écriture
     * peut différer de celui du rejeu.
     */
    public function designates(User $user): bool
    {
        return $user->getId() === $this->id
            && $user->getCreatedAt()->getTimestamp() === $this->createdAt->getTimestamp();
    }
}
