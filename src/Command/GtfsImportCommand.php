<?php

declare(strict_types=1);

namespace App\Command;

use App\Gtfs\Static\InvalidGtfsFeedException;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Monitoring\HealthcheckPinger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Lancée chaque nuit à 3 h par le worker, avant la purge (3 h 15) et la
 * sauvegarde (3 h 30), avec --if-changed : le référentiel n'est importé que
 * s'il a changé. Chaque réussite est signalée à Healthchecks.io.
 */
#[AsCommand(
    name: 'app:gtfs:import',
    description: 'Télécharge et importe le référentiel GTFS statique (routes, quais)',
)]
#[AsCronTask('0 3 * * *', timezone: 'Europe/Paris', arguments: '--if-changed')]
class GtfsImportCommand extends Command
{
    public function __construct(
        private readonly StaticReferenceImporter $importer,
        private readonly HealthcheckPinger $pinger,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'HEALTHCHECKS_GTFS_URL')]
        private readonly string $healthcheckUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('if-changed', null, InputOption::VALUE_NONE, 'Saute l\'import si le serveur répond que le fichier n\'a pas changé depuis le dernier import réussi');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import du GTFS statique');

        try {
            $summary = $this->importer->import(onlyIfChanged: (bool) $input->getOption('if-changed'));
        } catch (InvalidGtfsFeedException $exception) {
            return $this->fail($io, 'Flux GTFS invalide : '.$exception->getMessage());
        } catch (HttpExceptionInterface $exception) {
            return $this->fail($io, \sprintf('Impossible de télécharger le flux GTFS : HTTP %d.', $exception->getResponse()->getStatusCode()));
        } catch (HttpClientExceptionInterface $exception) {
            return $this->fail($io, 'Impossible de télécharger le flux GTFS : '.$exception->getMessage());
        }

        // Lancée par le scheduler, la sortie console est perdue : le log reste.
        if (!$summary instanceof \App\Gtfs\Static\GtfsImportSummary) {
            $this->logger->info('GTFS import: the feed is unchanged, import skipped.');
            $io->success('Référentiel inchangé : import sauté.');
        } else {
            $this->logger->info('GTFS import done: {summary}', ['summary' => (string) $summary]);
            $io->success((string) $summary);
        }

        $this->pinger->ping($this->healthcheckUrl);

        return Command::SUCCESS;
    }

    /**
     * Pas de ping : c'est son absence qui alerte, et l'erreur remonte par les logs.
     */
    private function fail(SymfonyStyle $io, string $error): int
    {
        $this->logger->error('GTFS import failed: {error}', ['error' => $error]);
        $io->error($error);

        return Command::FAILURE;
    }
}
