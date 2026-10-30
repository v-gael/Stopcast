<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\DataPurger;
use App\Account\UserInviter;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\Persistence\repository;

final class DataPurgerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    protected function setUp(): void
    {
        self::mockTime();
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
        self::assertStringContainsString('Demandes de réinitialisation supprimées 1', $display);
    }

    private function invite(string $email): User
    {
        $user = new User($email);
        $this->service(UserInviter::class)->invite($user);

        return $user;
    }

    /**
     * La date de création suit l'horloge système, pas celle des tests : elle s'écrit en base.
     */
    private function createPendingUser(string $email, string $createdAgo): void
    {
        UserFactory::new()->pending()->create(['email' => $email]);

        $this->service(EntityManagerInterface::class)->createQueryBuilder()
            ->update(User::class, 'u')
            ->set('u.createdAt', ':createdAt')
            ->where('u.email = :email')
            ->setParameter('createdAt', new \DateTimeImmutable($createdAgo))
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
    }

    private function userExists(string $email): bool
    {
        return null !== repository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
