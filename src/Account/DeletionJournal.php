<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Liste des comptes supprimés, une ligne JSON par suppression, rejouée après
 * la restauration d'une sauvegarde pour que ces comptes ne reviennent pas.
 *
 * Le fichier vit hors de la base, que la restauration écraserait. Une ligne
 * ne porte que l'identifiant et la date de création du compte (pas d'email) :
 * la date évite de supprimer un compte créé plus tard sous le même
 * identifiant, la séquence étant revenue à l'état de la sauvegarde.
 */
final readonly class DeletionJournal
{
    public function __construct(
        #[Autowire(param: 'app.account_deletions_log')]
        private string $path,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Le verrou exclusif sérialise l'ajout avec les autres ajouts et avec la
     * purge, dans app comme dans worker, qui partagent le fichier.
     */
    public function record(User $user): void
    {
        $id = $user->getId() ?? throw new \LogicException('Only a persisted account can be recorded.');

        $this->filesystem->appendToFile($this->path, json_encode([
            'id' => $id,
            'created_at' => $user->getCreatedAt()->format(\DATE_ATOM),
            'deleted_at' => $this->clock->now()->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR)."\n", lock: true);
    }

    /**
     * Un journal absent (aucune suppression encore) est vide.
     *
     * @return list<DeletionJournalEntry>
     *
     * @throws UnreadableDeletionJournalException
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->readLines() as $index => $line) {
            $entries[] = $this->parse($line) ?? throw new UnreadableDeletionJournalException($index + 1);
        }

        return $entries;
    }

    /**
     * Retire les suppressions plus anciennes que la date donnée et renvoie leur
     * nombre. Une ligne illisible est gardée : elle se corrige à la main.
     *
     * Le fichier est réécrit sur place, sous le même verrou que les ajouts.
     * Un fichier temporaire renommé ensuite laisserait un ajout concurrent
     * écrire dans l'ancien fichier, perdu au renommage.
     */
    public function purgeBefore(\DateTimeImmutable $threshold): int
    {
        if (!is_file($this->path)) {
            return 0;
        }

        $handle = fopen($this->path, 'c+') ?: throw new IOException(\sprintf('Failed to open "%s".', $this->path), path: $this->path);

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new IOException(\sprintf('Failed to lock "%s".', $this->path), path: $this->path);
            }

            $content = stream_get_contents($handle);
            if (false === $content) {
                throw new IOException(\sprintf('Failed to read "%s".', $this->path), path: $this->path);
            }

            $lines = $this->splitLines($content);
            $kept = [];
            foreach ($lines as $index => $line) {
                $entry = $this->parse($line);
                if (!$entry instanceof DeletionJournalEntry) {
                    $this->logger->warning('Purge: line {line} of the deletion journal cannot be read, it is kept.', ['line' => $index + 1]);
                } elseif ($entry->deletedAt < $threshold) {
                    continue;
                }

                $kept[] = $line."\n";
            }

            $removed = \count($lines) - \count($kept);
            if ($removed > 0) {
                $this->rewrite($handle, implode('', $kept));
            }

            return $removed;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function rewrite($handle, string $content): void
    {
        if (!ftruncate($handle, 0) || !rewind($handle) || false === fwrite($handle, $content) || !fflush($handle)) {
            throw new IOException(\sprintf('Failed to rewrite "%s".', $this->path), path: $this->path);
        }
    }

    /**
     * @return list<string>
     */
    private function readLines(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        return $this->splitLines($this->filesystem->readFile($this->path));
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        // Le fichier finit par un saut de ligne : la dernière ligne est vide.
        $lines = explode("\n", $content);
        if ('' === end($lines)) {
            array_pop($lines);
        }

        return $lines;
    }

    private function parse(string $line): ?DeletionJournalEntry
    {
        $data = json_decode($line, true);
        if (!\is_array($data) || !\is_int($data['id'] ?? null)) {
            return null;
        }

        $createdAt = $this->parseDate($data['created_at'] ?? null);
        $deletedAt = $this->parseDate($data['deleted_at'] ?? null);
        if (!$createdAt instanceof \DateTimeImmutable || !$deletedAt instanceof \DateTimeImmutable) {
            return null;
        }

        return new DeletionJournalEntry($data['id'], $createdAt, $deletedAt);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!'.\DATE_ATOM, $value) ?: null;
    }
}
