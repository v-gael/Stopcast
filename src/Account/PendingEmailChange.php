<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;

/**
 * Changement d'email demandé par un lien valable, pas encore appliqué.
 */
final readonly class PendingEmailChange
{
    public function __construct(
        public User $user,
        public string $newEmail,
    ) {
    }
}
