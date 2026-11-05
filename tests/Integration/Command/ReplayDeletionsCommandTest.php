<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\DeletionJournalTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\Persistence\repository;

final class ReplayDeletionsCommandTest extends KernelTestCase
{
    use DeletionJournalTrait;
    use Factories;
    use ResetDatabase;

    protected function setUp(): void
    {
        $this->clearDeletionJournal();
    }

    public function testDeletesTheAccountsOfTheJournalWithoutEmailNorNewLine(): void
    {
        $deleted = UserFactory::createOne(['email' => 'lea@example.com']);
        UserFactory::createOne(['email' => 'reste@example.com']);
        $this->appendEntryFor($deleted);
        $journal = (string) file_get_contents($this->deletionJournalPath());

        $tester = $this->command();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->userExists('lea@example.com'));
        self::assertTrue($this->userExists('reste@example.com'));
        self::assertQueuedEmailCount(0);
        self::assertSame($journal, file_get_contents($this->deletionJournalPath()));
        self::assertStringContainsString('Comptes supprimés : 1.', $this->display($tester));
    }

    public function testIgnoresAMissingAccountAndAnAccountCreatedAgainUnderTheSameId(): void
    {
        $recreated = UserFactory::createOne(['email' => 'nouveau@example.com']);
        $this->appendEntryFor($recreated, createdAt: $recreated->getCreatedAt()->modify('-1 day'));
        $this->appendToDeletionJournal($this->deletionJournalLine((int) $recreated->getId() + 1000, deletedAt: new \DateTimeImmutable('2026-02-01 10:00:00')));

        $tester = $this->command();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->userExists('nouveau@example.com'));
        $display = $this->display($tester);
        self::assertStringContainsString('Comptes à supprimer 0', $display);
        self::assertStringContainsString('Lignes ignorées, compte absent 1', $display);
        self::assertStringContainsString('Lignes ignorées, date de création différente 1', $display);
    }

    public function testComparesCreationDatesAsInstantsWhateverTheirTimezone(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user, createdAt: $user->getCreatedAt()->setTimezone(new \DateTimeZone('America/New_York')));

        $tester = $this->command();
        $tester->execute([], ['interactive' => false]);

        self::assertFalse($this->userExists('lea@example.com'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableLines(): iterable
    {
        yield 'JSON invalide' => ['{"id": 12,'];
        yield 'champ manquant' => ['{"id": 12, "deleted_at": "2026-02-01T10:00:00+01:00"}'];
        yield 'identifiant non entier' => ['{"id": "12", "created_at": "2026-01-01T10:00:00+01:00", "deleted_at": "2026-02-01T10:00:00+01:00"}'];
        yield 'date illisible' => ['{"id": 12, "created_at": "hier", "deleted_at": "2026-02-01T10:00:00+01:00"}'];
    }

    #[DataProvider('unreadableLines')]
    public function testAnUnreadableLineStopsTheReplayBeforeAnyDeletion(string $line): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user);
        $this->appendToDeletionJournal($line);

        $tester = $this->command();
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('ligne 2', $this->display($tester));
        self::assertTrue($this->userExists('lea@example.com'));
    }

    public function testAMissingJournalReplaysNothing(): void
    {
        $tester = $this->command();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Comptes supprimés : 0.', $this->display($tester));
    }

    public function testDryRunDeletesNothing(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user);

        $tester = $this->command();
        $tester->execute(['--dry-run' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->userExists('lea@example.com'));
        self::assertStringContainsString('Comptes à supprimer 1', $this->display($tester));
        self::assertStringContainsString('Simulation : aucun compte supprimé.', $this->display($tester));
    }

    public function testAsksForConfirmationAndDeletesNothingWhenRefused(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user);

        $tester = $this->command();
        $tester->setInputs(['no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Supprimer 1 comptes ?', $this->display($tester));
        self::assertStringContainsString('Rejeu annulé : aucun compte supprimé.', $this->display($tester));
        self::assertTrue($this->userExists('lea@example.com'));
    }

    public function testDeletesOnceConfirmed(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user);

        $tester = $this->command();
        $tester->setInputs(['yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->userExists('lea@example.com'));
    }

    public function testVerboseListsTheIdsButNeverAnEmail(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $this->appendEntryFor($user);

        $tester = $this->command();
        $tester->execute(['--dry-run' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString('* '.$user->getId(), $tester->getDisplay());
        self::assertStringNotContainsString('lea@example.com', $tester->getDisplay());
    }

    /**
     * La ligne qu'aurait écrite la suppression du compte, sans le supprimer :
     * la base est celle d'une sauvegarde antérieure.
     */
    private function appendEntryFor(User $user, ?\DateTimeImmutable $createdAt = null): void
    {
        $this->appendToDeletionJournal($this->deletionJournalLine(
            (int) $user->getId(),
            deletedAt: new \DateTimeImmutable(),
            createdAt: $createdAt ?? $user->getCreatedAt(),
        ));
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('app:account:replay-deletions'));
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function userExists(string $email): bool
    {
        return null !== repository(User::class)->findOneBy(['email' => $email]);
    }
}
