<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\DataPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Lancée chaque nuit par le worker, entre l'import GTFS (3 h) et la
 * sauvegarde (3 h 30).
 */
#[AsCommand(
    name: 'app:purge',
    description: 'Supprime les données arrivées au bout de leur durée de conservation',
)]
#[AsCronTask('15 3 * * *', timezone: 'Europe/Paris')]
class PurgeCommand extends Command
{
    public function __construct(
        private readonly DataPurger $purger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Purge des données expirées');

        $summary = $this->purger->purge();

        $io->definitionList(
            ['Comptes en attente supprimés' => $summary->pendingAccounts],
            ['Comptes inactifs avertis' => $summary->inactivityWarnings],
            ['Comptes inactifs supprimés' => $summary->inactiveAccounts],
            ['Demandes de réinitialisation supprimées' => $summary->resetPasswordRequests],
        );
        $io->success('Purge terminée.');

        return Command::SUCCESS;
    }
}
