<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\AccountDeleter;
use App\Account\DeletionJournal;
use App\Account\DeletionJournalEntry;
use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\DeletionJournalTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\MailerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\Persistence\repository;

final class AccountDeleterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DeletionJournalTrait;
    use Factories;
    use ResetDatabase;

    protected function setUp(): void
    {
        // Sans argument, l'horloge simulée serait en UTC et non dans le fuseau de PHP.
        self::mockTime(new \DateTimeImmutable('2026-11-05 10:00:00'));
        $this->clearDeletionJournal();
    }

    public function testDeletingAnAccountRecordsItsIdAndCreationDateInTheJournal(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        $id = $user->getId();
        $createdAt = $user->getCreatedAt();

        $this->accountDeleter()->delete($user);

        $entries = $this->deletionJournal()->entries();
        self::assertCount(1, $entries);
        self::assertSame($id, $entries[0]->id);
        self::assertSame($createdAt->getTimestamp(), $entries[0]->createdAt->getTimestamp());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 10:00:00'), $entries[0]->deletedAt);
        self::assertStringNotContainsString('lea@example.com', (string) file_get_contents($this->deletionJournalPath()));
    }

    public function testCancellingAnInvitationIsRecordedToo(): void
    {
        $user = UserFactory::new()->pending()->create();
        $id = $user->getId();

        $this->accountDeleter()->delete($user);

        self::assertSame([$id], array_map(static fn (DeletionJournalEntry $entry): int => $entry->id, $this->deletionJournal()->entries()));
    }

    public function testAnAccountIsNotDeletedWhenTheJournalCannotBeWritten(): void
    {
        $user = UserFactory::createOne(['email' => 'lea@example.com']);
        // Le dossier du journal est un fichier : l'écriture échoue.
        $blocker = (string) tempnam(sys_get_temp_dir(), 'journal');
        $deleter = new AccountDeleter(
            $this->service(EntityManagerInterface::class),
            $this->service(MailerInterface::class),
            new DeletionJournal($blocker.'/deletions.jsonl', new Filesystem(), $this->service(ClockInterface::class), new NullLogger()),
        );

        try {
            $deleter->delete($user);
            self::fail('The deletion should have failed.');
        } catch (IOException) {
        } finally {
            unlink($blocker);
        }

        self::assertNotNull(repository(User::class)->findOneBy(['email' => 'lea@example.com']));
        self::assertQueuedEmailCount(0);
    }

    private function accountDeleter(): AccountDeleter
    {
        return $this->service(AccountDeleter::class);
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
