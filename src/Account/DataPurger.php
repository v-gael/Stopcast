<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Applique les durées de conservation : supprime les comptes en attente dont
 * la dernière invitation a expiré depuis plus de 30 jours, avertit puis
 * supprime les comptes activés sans connexion depuis 2 ans, et supprime enfin
 * les demandes de réinitialisation et d'invitation expirées des comptes actifs,
 * puis les lignes du journal des suppressions plus vieilles que les sauvegardes
 * et les messages Messenger en échec depuis plus de 7 jours.
 *
 * Les demandes d'un compte en attente restent jusqu'à sa suppression : la
 * plus récente date sa dernière invitation, et elles partent en cascade avec
 * le compte.
 */
final readonly class DataPurger
{
    public const string PENDING_ACCOUNT_RETENTION = '30 days';

    public const string INACTIVE_ACCOUNT_RETENTION = '2 years';

    /**
     * Délai entre l'avertissement et la suppression d'un compte inactif.
     */
    public const string INACTIVITY_WARNING_NOTICE = '30 days';

    /**
     * Durée des sauvegardes, annoncée dans la politique de confidentialité :
     * une ligne reste au journal tant qu'une sauvegarde antérieure à la
     * suppression peut être restaurée.
     */
    public const string DELETION_JOURNAL_RETENTION = '30 days';

    /**
     * Durée d'un message dans la file `failed` après son dernier échec : le
     * temps de voir l'alerte Sentry, d'enquêter et de relancer un mail encore
     * utile (l'avertissement d'inactivité l'est 30 jours). Les liens à jeton
     * ont expiré bien avant.
     */
    public const string FAILED_MESSAGE_RETENTION = '7 days';

    public function __construct(
        private UserRepository $userRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private AccountDeleter $accountDeleter,
        private DeletionJournal $deletionJournal,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(service: 'messenger.transport.failed')]
        private ListableReceiverInterface $failedTransport,
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
            inactivityWarnings: $this->warnInactiveAccounts($now),
            inactiveAccounts: $this->deleteInactiveAccounts($now),
            resetPasswordRequests: $this->resetPasswordRequestRepository->removeExpiredRequestsOfActiveUsers($now),
            // Après les suppressions de comptes : celles de cette purge y sont inscrites.
            deletionJournalEntries: $this->deletionJournal->purgeBefore($now->modify('-'.self::DELETION_JOURNAL_RETENTION)),
            failedMessages: $this->removeFailedMessages($now),
        );

        $this->logger->info('Purge: {pendingAccounts} pending accounts deleted, {inactivityWarnings} inactive accounts warned, {inactiveAccounts} inactive accounts deleted, {resetPasswordRequests} expired reset password requests deleted, {deletionJournalEntries} deletion journal lines removed and {failedMessages} failed messages removed.', [
            'pendingAccounts' => $summary->pendingAccounts,
            'inactivityWarnings' => $summary->inactivityWarnings,
            'inactiveAccounts' => $summary->inactiveAccounts,
            'resetPasswordRequests' => $summary->resetPasswordRequests,
            'deletionJournalEntries' => $summary->deletionJournalEntries,
            'failedMessages' => $summary->failedMessages,
        ]);

        return $summary;
    }

    private function warnInactiveAccounts(\DateTimeImmutable $now): int
    {
        $users = $this->userRepository->findInactiveToWarn(
            $now->modify('-'.self::INACTIVE_ACCOUNT_RETENTION)->modify('+'.self::INACTIVITY_WARNING_NOTICE),
        );

        foreach ($users as $user) {
            $this->mailer->send(new TemplatedEmail()
                ->to($user->getEmail())
                ->subject('Votre compte Stopcast va être supprimé')
                ->htmlTemplate('email/inactivity_warning.html.twig')
                ->textTemplate('email/inactivity_warning.txt.twig')
            );

            // Le mail n'est qu'en file à ce stade : s'il échoue, il faut le relancer à la main
            // (docs/exploitation.md, « Mails en échec »), sinon le compte sera supprimé sans avertissement.
            $user->markInactivityWarned($now);
            $this->entityManager->flush();
        }

        return \count($users);
    }

    /**
     * Le délai court depuis l'avertissement : un compte n'est jamais supprimé
     * moins de 30 jours après son mail.
     */
    private function deleteInactiveAccounts(\DateTimeImmutable $now): int
    {
        $users = $this->userRepository->findInactiveToDelete(
            $now->modify('-'.self::INACTIVE_ACCOUNT_RETENTION),
            $now->modify('-'.self::INACTIVITY_WARNING_NOTICE),
        );

        $deleted = 0;
        foreach ($users as $user) {
            if ($this->isLastAdmin($user)) {
                $this->logger->warning('Purge: inactive account {id} not deleted, it is the last administrator.', [
                    'id' => $user->getId(),
                ]);

                continue;
            }

            $this->accountDeleter->delete($user);
            ++$deleted;
        }

        return $deleted;
    }

    /**
     * Toute la file, quelle que soit la classe du message. La date est celle
     * du dernier échec, qu'affiche `messenger:failed:show` : un message relancé
     * qui échoue de nouveau repart pour la durée entière. Un message sans date
     * (illisible, il a perdu ses stamps) est gardé et signalé.
     */
    private function removeFailedMessages(\DateTimeImmutable $now): int
    {
        $failedBefore = $now->modify('-'.self::FAILED_MESSAGE_RETENTION);

        $removed = 0;
        foreach ($this->failedTransport->all() as $envelope) {
            $failedAt = $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt();
            if (null === $failedAt) {
                $this->logger->warning('Purge: failed message {id} has no failure date, it is kept.', [
                    'id' => $envelope->last(TransportMessageIdStamp::class)?->getId(),
                ]);

                continue;
            }

            if ($failedAt < $failedBefore) {
                $this->failedTransport->reject($envelope);
                ++$removed;
            }
        }

        return $removed;
    }

    private function isLastAdmin(User $user): bool
    {
        return $user->isAdmin() && $this->userRepository->countActiveAdmins() <= 1;
    }
}
