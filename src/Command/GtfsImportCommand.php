<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\InvalidGtfsFeedException;
use App\Service\GtfsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsCommand(
    name: 'app:gtfs:import',
    description: 'Télécharge et importe le référentiel GTFS statique (routes, quais)',
)]
class GtfsImportCommand extends Command
{
    public function __construct(
        private readonly GtfsService $gtfsService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import du GTFS statique');

        try {
            $summary = $this->gtfsService->importStaticFeed();
        } catch (InvalidGtfsFeedException $exception) {
            $io->error('Flux GTFS invalide : '.$exception->getMessage());

            return Command::FAILURE;
        } catch (TransportExceptionInterface $exception) {
            $io->error('Impossible de télécharger le flux GTFS : '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success((string) $summary);

        return Command::SUCCESS;
    }
}
