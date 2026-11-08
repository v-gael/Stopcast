<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\DataPurger;
use App\Account\DeletionJournalEntry;
use App\Account\UserInviter;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\AppLogsTrait;
use App\Tests\Support\ContainerServiceTrait;
use App\Tests\Support\DeletionJournalTrait;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Symfony\Component\Clock\now;
use function Zenstruck\Foundry\Persistence\repository;

final class DataPurgerTest extends KernelTestCase
{
    use AppLogsTrait;
    use ClockSensitiveTrait;
    use ContainerServiceTrait;
    use DeletionJournalTrait;
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    protected function setUp(): void
    {
        // Sans argument, l'horloge simulée serait en UTC et non dans le fuseau de PHP.
        self::mockTime(new \DateTimeImmutable());
        $this->clearDeletionJournal();
    }

    public function testDeletionJournalLinesOlderThan30DaysAreRemoved(): void
    {
        $this->appendToDeletionJournal(
            $this->deletionJournalLine(1, deletedAt: now('-30 days -1 minute')),
            $this->deletionJournalLine(2, deletedAt: now('-29 days -23 hours')),
            $this->deletionJournalLine(3, deletedAt: now('-1 day')),
        );

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->deletionJournalEntries);
        self::assertSame([2, 3], array_map(static fn (DeletionJournalEntry $entry): int => $entry->id, $this->deletionJournal()->entries()));
    }

    public function testUnreadableDeletionJournalLineIsKeptAndAWarningIsLogged(): void
    {
        $this->appendToDeletionJournal(
            $this->deletionJournalLine(1, deletedAt: now('-31 days')),
            '{"id": 12,',
            $this->deletionJournalLine(3, deletedAt: now('-1 day')),
        );
        $logs = $this->appLogs();

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->deletionJournalEntries);
        self::assertSame(
            '{"id": 12,'."\n".$this->deletionJournalLine(3, deletedAt: now('-1 day'))."\n",
            file_get_contents($this->deletionJournalPath()),
        );
        self::assertTrue($logs->hasWarning(['message' => 'Purge: line {line} of the deletion journal cannot be read, it is kept.', 'context' => ['line' => 2]]));
    }

    public function testMissingDeletionJournalPurgesNothing(): void
    {
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->deletionJournalEntries);
        self::assertFileDoesNotExist($this->deletionJournalPath());
    }

    public function testPendingAccountIsDeleted31DaysAfterItsInvitationExpired(): void
    {
        $this->invite(self::EMAIL);

        self::mockTime('+1 day +31 days');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->pendingAccounts);
        self::assertFalse($this->userExists(self::EMAIL));
        self::assertSame(0, repository(ResetPasswordRequest::class)->count());
        self::assertQueuedEmailCount(1, message: 'Seule l\'invitation part : un compte en attente supprimé ne reçoit pas de mail.');
    }

    public function testPendingAccountStays29DaysAfterItsInvitationExpired(): void
    {
        $this->invite(self::EMAIL);

        self::mockTime('+1 day +29 days');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->pendingAccounts);
        self::assertTrue($this->userExists(self::EMAIL));
    }

    public function testRecentlyResentInvitationKeepsThePendingAccount(): void
    {
        $user = $this->invite(self::EMAIL);

        self::mockTime('+40 days');
        $this->service(UserInviter::class)->resend($user);

        self::mockTime('+2 days');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->pendingAccounts);
        self::assertTrue($this->userExists(self::EMAIL));
    }

    public function testPendingAccountWithoutInvitationIsDeletedAfterTheRetentionAndTheLinkLifetime(): void
    {
        $this->createPendingUser('ancien@example.com', '-31 days -1 hour');
        $this->createPendingUser('recent@example.com', '-30 days -23 hours');

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->pendingAccounts);
        self::assertFalse($this->userExists('ancien@example.com'));
        self::assertTrue($this->userExists('recent@example.com'));
    }

    public function testExpiredRequestOfAnActiveAccountIsDeleted(): void
    {
        $active = UserFactory::createOne(['email' => self::EMAIL]);
        $this->service(ResetPasswordHelperInterface::class)->generateResetToken($active);

        self::mockTime('+1 hour');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->resetPasswordRequests);
        self::assertSame(0, repository(ResetPasswordRequest::class)->count());
    }

    public function testValidRequestOfAnActiveAccountStays(): void
    {
        $active = UserFactory::createOne(['email' => self::EMAIL]);
        $this->service(ResetPasswordHelperInterface::class)->generateResetToken($active);

        self::mockTime('+59 minutes');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->resetPasswordRequests);
        self::assertSame(1, repository(ResetPasswordRequest::class)->count());
    }

    public function testExpiredInvitationOfAPendingAccountStays(): void
    {
        $this->invite(self::EMAIL);

        self::mockTime('+2 days');
        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->resetPasswordRequests);
        self::assertSame(1, repository(ResetPasswordRequest::class)->count());
    }

    public function testInactiveAccountIsWarnedOnceAfter23MonthsAndADay(): void
    {
        $this->createActiveUser(self::EMAIL, lastLoginAgo: '-2 years +30 days -1 day');
        $this->createActiveUser('recente@example.com', lastLoginAgo: '-2 years +30 days +1 day');

        $first = $this->service(DataPurger::class)->purge();
        $second = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $first->inactivityWarnings);
        self::assertSame(0, $second->inactivityWarnings);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Votre compte Stopcast va être supprimé');
        self::assertEmailTextBodyContains($email, 'supprimé, avec ses écrans');
        self::assertEmailTextBodyContains($email, 'http://localhost/');
        self::assertEmailHtmlBodyContains($email, 'href="http://localhost/"');

        // La base garde des secondes entières.
        self::assertEqualsWithDelta(now(), $this->storedUser(self::EMAIL)->getInactivityWarnedAt(), 1);
        self::assertNull($this->storedUser('recente@example.com')->getInactivityWarnedAt());
        self::assertTrue($this->userExists(self::EMAIL));
    }

    public function testInactiveAccountIsDeletedTwoYearsOnWhenItsWarningIsOlderThan30Days(): void
    {
        $this->createActiveUser(self::EMAIL, lastLoginAgo: '-2 years -1 day', warnedAgo: '-30 days -1 hour');

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->inactiveAccounts);
        self::assertFalse($this->userExists(self::EMAIL));
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Votre compte Stopcast a été supprimé');
    }

    public function testAccountDeletedByThePurgeIsRecordedInTheDeletionJournal(): void
    {
        $user = $this->createActiveUser(self::EMAIL, lastLoginAgo: '-2 years -1 day', warnedAgo: '-30 days -1 hour');
        $id = $user->getId();

        $this->service(DataPurger::class)->purge();

        self::assertSame([$id], array_map(static fn (DeletionJournalEntry $entry): int => $entry->id, $this->deletionJournal()->entries()));
    }

    public function testInactiveAccountWarned10DaysAgoStays(): void
    {
        $this->createActiveUser(self::EMAIL, lastLoginAgo: '-3 years', warnedAgo: '-10 days');

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->inactiveAccounts);
        self::assertSame(0, $summary->inactivityWarnings);
        self::assertTrue($this->userExists(self::EMAIL));
        self::assertQueuedEmailCount(0);
    }

    public function testLastAdministratorIsNeverDeletedAndAWarningIsLogged(): void
    {
        $admin = $this->createActiveUser('admin@example.com', lastLoginAgo: '-2 years -1 day', warnedAgo: '-31 days', role: Role::Admin);
        $logs = $this->appLogs();

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->inactiveAccounts);
        self::assertTrue($this->userExists('admin@example.com'));
        self::assertTrue($logs->hasWarning(['message' => 'Purge: inactive account {id} not deleted, it is the last administrator.', 'context' => ['id' => $admin->getId()]]));
        $warning = array_first(array_filter($logs->getRecords(), static fn ($record): bool => Level::Warning === $record->level));
        self::assertInstanceOf(LogRecord::class, $warning);
        self::assertSame('app', $warning->channel);
    }

    public function testInactiveAdministratorWhoIsNotTheLastIsDeleted(): void
    {
        $this->createActiveUser('admin@example.com', lastLoginAgo: '-2 years -1 day', warnedAgo: '-31 days', role: Role::Admin);
        $this->createActiveUser('autre-admin@example.com', lastLoginAgo: '-1 day', role: Role::Admin);
        $logs = $this->appLogs();

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->inactiveAccounts);
        self::assertFalse($this->userExists('admin@example.com'));
        self::assertFalse($logs->hasWarningRecords());
    }

    public function testAccountNeverLoggedInIsWarnedThenDeletedAfterItsCreationDate(): void
    {
        $this->createActiveUser(self::EMAIL, createdAgo: '-2 years -1 day');

        $warned = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $warned->inactivityWarnings);
        self::assertSame(0, $warned->inactiveAccounts);
        self::assertTrue($this->userExists(self::EMAIL));

        self::mockTime('+29 days');
        self::assertSame(0, $this->service(DataPurger::class)->purge()->inactiveAccounts);

        self::mockTime('+1 day +1 second');
        self::assertSame(1, $this->service(DataPurger::class)->purge()->inactiveAccounts);
        self::assertFalse($this->userExists(self::EMAIL));
    }

    public function testCommandDisplaysTheCounts(): void
    {
        $this->invite(self::EMAIL);
        $this->service(ResetPasswordHelperInterface::class)->generateResetToken(UserFactory::createOne());

        self::mockTime('+1 day +31 days');
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Comptes en attente supprimés 1', $display);
        self::assertStringContainsString('Comptes inactifs avertis 0', $display);
        self::assertStringContainsString('Comptes inactifs supprimés 0', $display);
        self::assertStringContainsString('Demandes de réinitialisation supprimées 1', $display);
        self::assertStringContainsString('Lignes du journal des suppressions retirées 0', $display);
        self::assertStringContainsString('Messages en échec supprimés 0', $display);
    }

    public function testFailedMessagesOlderThan7DaysAreRemoved(): void
    {
        $this->sendToFailedQueue('old@example.com', failedAt: now('-7 days -1 minute'));
        $this->sendToFailedQueue('recent@example.com', failedAt: now('-6 days'));

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(1, $summary->failedMessages);
        self::assertSame(['recent@example.com'], $this->failedQueueRecipients());
    }

    public function testFailedMessageWithoutFailureDateIsKeptAndAWarningIsLogged(): void
    {
        $this->sendToFailedQueue('undated@example.com', failedAt: null);
        $logs = $this->appLogs();

        $summary = $this->service(DataPurger::class)->purge();

        self::assertSame(0, $summary->failedMessages);
        self::assertSame(['undated@example.com'], $this->failedQueueRecipients());
        self::assertTrue($logs->hasWarningThatContains('Purge: failed message {id} has no failure date, it is kept.'));
    }

    /**
     * Sans date d'échec, le message n'a pas de `RedeliveryStamp`, comme un
     * message que Messenger n'a pas pu relire.
     */
    private function sendToFailedQueue(string $recipient, ?\DateTimeImmutable $failedAt): void
    {
        $email = new Email()->from('no-reply@stopcast.local')->to($recipient)->subject('Invitation')->text('Lien');
        $stamps = $failedAt instanceof \DateTimeImmutable ? [new RedeliveryStamp(0, $failedAt)] : [];
        $this->failedTransport()->send(new Envelope(new SendEmailMessage($email), $stamps));
    }

    /**
     * @return list<string>
     */
    private function failedQueueRecipients(): array
    {
        $recipients = [];
        foreach ($this->failedTransport()->all() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SendEmailMessage::class, $message);
            $email = $message->getMessage();
            self::assertInstanceOf(Email::class, $email);
            $recipients[] = $email->getTo()[0]->getAddress();
        }

        return $recipients;
    }

    private function failedTransport(): TransportInterface&ListableReceiverInterface
    {
        $transport = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $transport);
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return $transport;
    }

    private function invite(string $email): User
    {
        $user = new User($email);
        $this->service(UserInviter::class)->invite($user);

        return $user;
    }

    /**
     * Compte activé, connecté pour la dernière fois et averti aux dates
     * données par rapport à maintenant (null : jamais).
     */
    private function createActiveUser(
        string $email,
        ?string $lastLoginAgo = null,
        ?string $warnedAgo = null,
        string $createdAgo = '-3 years',
        Role $role = Role::User,
    ): User {
        $user = UserFactory::new()
            ->afterInstantiate(static function (User $user) use ($lastLoginAgo, $warnedAgo): void {
                if (null !== $lastLoginAgo) {
                    $user->recordLogin(now($lastLoginAgo));
                }

                if (null !== $warnedAgo) {
                    $user->markInactivityWarned(now($warnedAgo));
                }
            })
            ->create(['email' => $email, 'role' => $role]);
        $this->setCreatedAt($email, now($createdAgo));

        return $user;
    }

    /**
     * La date de création suit l'horloge système, pas celle des tests : elle s'écrit en base.
     */
    private function createPendingUser(string $email, string $createdAgo): void
    {
        UserFactory::new()->pending()->create(['email' => $email]);
        $this->setCreatedAt($email, new \DateTimeImmutable($createdAgo));
    }

    private function setCreatedAt(string $email, \DateTimeImmutable $createdAt): void
    {
        $this->service(EntityManagerInterface::class)->createQueryBuilder()
            ->update(User::class, 'u')
            ->set('u.createdAt', ':createdAt')
            ->where('u.email = :email')
            ->setParameter('createdAt', $createdAt)
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
    }

    private function storedUser(string $email): User
    {
        $this->service(EntityManagerInterface::class)->clear();
        $user = repository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userExists(string $email): bool
    {
        return null !== repository(User::class)->findOneBy(['email' => $email]);
    }
}
