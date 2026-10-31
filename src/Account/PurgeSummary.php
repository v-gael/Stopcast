<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Nombre d'éléments traités par une purge, par sorte.
 */
final readonly class PurgeSummary
{
    public function __construct(
        public int $pendingAccounts,
        public int $inactivityWarnings,
        public int $inactiveAccounts,
        public int $resetPasswordRequests,
    ) {
    }
}
