<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Account\DeletionJournal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Journal des suppressions de compte d'un test : un fichier partagé par toute
 * la suite, vidé avant chaque test qui le lit.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait DeletionJournalTrait
{
    private function clearDeletionJournal(): void
    {
        $path = $this->deletionJournalPath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function deletionJournal(): DeletionJournal
    {
        $journal = self::getContainer()->get(DeletionJournal::class);
        self::assertInstanceOf(DeletionJournal::class, $journal);

        return $journal;
    }

    private function deletionJournalPath(): string
    {
        $path = self::getContainer()->getParameter('app.account_deletions_log');
        self::assertIsString($path);

        return $path;
    }

    /**
     * Une ligne au format qu'écrit DeletionJournal::record().
     */
    private function deletionJournalLine(int $id, \DateTimeImmutable $deletedAt, ?\DateTimeImmutable $createdAt = null): string
    {
        return json_encode([
            'id' => $id,
            'created_at' => ($createdAt ?? new \DateTimeImmutable('2025-01-01 10:00:00'))->format(\DATE_ATOM),
            'deleted_at' => $deletedAt->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Ajoute des lignes telles quelles, par exemple pour simuler un journal abîmé.
     */
    private function appendToDeletionJournal(string ...$lines): void
    {
        $path = $this->deletionJournalPath();
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }

        file_put_contents($path, implode('', array_map(static fn (string $line): string => $line."\n", $lines)), \FILE_APPEND);
    }
}
