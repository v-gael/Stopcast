<?php

declare(strict_types=1);

namespace App\Account;

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
}
