<?php

declare(strict_types=1);

namespace App\Account;

use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applique les durées de conservation : supprime les comptes en attente dont
 * la dernière invitation a expiré depuis plus de 30 jours, puis les demandes
 * de réinitialisation et d'invitation expirées des comptes actifs.
 *
 * Les demandes d'un compte en attente restent jusqu'à sa suppression : la
 * plus récente date sa dernière invitation, et elles partent en cascade avec
 * le compte.
 */
final readonly class DataPurger
{
    public const string PENDING_ACCOUNT_RETENTION = '30 days';

    public function __construct(
        private UserRepository $userRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private AccountDeleter $accountDeleter,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function purge(): PurgeSummary
    {
        $now = $this->clock->now();
        $invitationExpiredBefore = $now->modify('-'.self::PENDING_ACCOUNT_RETENTION);

        // Sans demande, la dernière invitation a expiré au plus tard une durée de lien après la création.
        $pendingUsers = $this->userRepository->findPendingToPurge(
            $invitationExpiredBefore,
            $invitationExpiredBefore->modify(\sprintf('-%d seconds', UserInviter::INVITATION_LIFETIME)),
        );
        foreach ($pendingUsers as $user) {
            $this->accountDeleter->delete($user);
        }

        $summary = new PurgeSummary(
            pendingAccounts: \count($pendingUsers),
            resetPasswordRequests: $this->resetPasswordRequestRepository->removeExpiredRequestsOfActiveUsers($now),
        );

        $this->logger->info('Purge: {pendingAccounts} pending accounts and {resetPasswordRequests} expired reset password requests deleted.', [
            'pendingAccounts' => $summary->pendingAccounts,
            'resetPasswordRequests' => $summary->resetPasswordRequests,
        ]);

        return $summary;
    }
}
