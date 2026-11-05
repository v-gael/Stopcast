<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\AccountDeleter;
use App\Account\DeletionJournal;
use App\Account\UnreadableDeletionJournalException;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * À lancer après la restauration d'une sauvegarde, app et worker arrêtés :
 * supprime de nouveau les comptes supprimés depuis la sauvegarde.
 */
#[AsCommand(
    name: 'app:account:replay-deletions',
    description: 'Rejoue les suppressions de compte du journal après une restauration',
)]
class ReplayDeletionsCommand extends Command
{
    public function __construct(
        private readonly DeletionJournal $journal,
        private readonly UserRepository $userRepository,
        private readonly AccountDeleter $accountDeleter,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les suppressions à rejouer sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rejeu des suppressions de compte');

        /** @var array<int, User> $toDelete */
        $toDelete = [];
        $missing = [];
        $recreated = [];

        try {
            $entries = $this->journal->entries();
        } catch (UnreadableDeletionJournalException $unreadableDeletionJournalException) {
            $io->error(\sprintf('La ligne %d du journal est illisible : corrigez-la, puis relancez. Aucun compte n\'a été supprimé.', $unreadableDeletionJournalException->lineNumber));

            return Command::FAILURE;
        }

        foreach ($entries as $entry) {
            $user = $this->userRepository->find($entry->id);
            if (null === $user) {
                $missing[] = $entry->id;
            } elseif (!$entry->designates($user)) {
                // Créé après la sauvegarde, sous un identifiant déjà utilisé.
                $recreated[] = $entry->id;
            } else {
                $toDelete[$entry->id] = $user;
            }
        }

        $io->definitionList(
            ['Comptes à supprimer' => \count($toDelete)],
            ['Lignes ignorées, compte absent' => \count($missing)],
            ['Lignes ignorées, date de création différente' => \count($recreated)],
        );

        // Les identifiants seulement, jamais d'email : la sortie peut finir dans un log.
        if ($io->isVerbose()) {
            $this->listIds($io, 'Comptes à supprimer', array_keys($toDelete));
            $this->listIds($io, 'Comptes absents', $missing);
            $this->listIds($io, 'Date de création différente', $recreated);
        }

        if ($input->getOption('dry-run')) {
            $io->note('Simulation : aucun compte supprimé.');

            return Command::SUCCESS;
        }

        // En mode non interactif (runbook scripté), la question est passée.
        if ([] !== $toDelete && $input->isInteractive() && !$io->confirm(\sprintf('Supprimer %d comptes ?', \count($toDelete)), false)) {
            $io->warning('Rejeu annulé : aucun compte supprimé.');

            return Command::SUCCESS;
        }

        // Tout ou rien : après un échec, la base reste celle de la sauvegarde
        // et la commande se relance.
        $this->entityManager->wrapInTransaction(function () use ($toDelete): void {
            foreach ($toDelete as $user) {
                $this->accountDeleter->replay($user);
            }
        });

        $io->success(\sprintf('Comptes supprimés : %d.', \count($toDelete)));

        return Command::SUCCESS;
    }

    /**
     * @param list<int> $ids
     */
    private function listIds(SymfonyStyle $io, string $title, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $io->section($title);
        $io->listing(array_map(strval(...), $ids));
    }
}
