<?php

declare(strict_types=1);

namespace App\Command;

use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Gtfs\Realtime\TripUpdatesFeed;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Diagnostic du flux temps réel, en une ligne : il est téléchargé sans passer
 * par le cache de l'appli, décodé et daté comme pour l'affichage. Les passages
 * à venir sont comptés selon les règles de l'affichage, mais dans le flux
 * seul, sans croisement avec la base (lignes inconnues, terminus). Code 0
 * si le flux est frais, 1 sinon. Ni log ni ping : c'est un outil de diagnostic.
 */
#[AsCommand(
    name: 'app:gtfs:realtime:check',
    description: 'Vérifie le flux temps réel : âge, entités, passages à venir',
)]
final class GtfsRealtimeCheckCommand extends Command
{
    public function __construct(
        private readonly TripUpdatesFeed $tripUpdatesFeed,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $prefix = \sprintf('%s %s', $now->format('H:i'), $this->tripUpdatesFeed->url());

        try {
            $feed = $this->tripUpdatesFeed->decode($this->tripUpdatesFeed->downloadRaw());
        } catch (HttpExceptionInterface $exception) {
            return $this->fail($output, $prefix, \sprintf('injoignable (HTTP %d)', $exception->getResponse()->getStatusCode()));
        } catch (HttpClientExceptionInterface $exception) {
            return $this->fail($output, $prefix, 'injoignable ('.$exception->getMessage().')');
        } catch (InvalidGtfsRealtimeFeedException $exception) {
            return $this->fail($output, $prefix, 'illisible ('.$exception->getMessage().')');
        }

        $age = $this->tripUpdatesFeed->ageOf($feed);

        $output->writeln(\sprintf(
            '%s header %s, âge %d s, %d entités, %d passages à venir : %s',
            $prefix,
            $age->publishedAt?->format('H:i:s') ?? 'sans timestamp',
            $age->seconds,
            \count($feed->getEntity()),
            \count($this->tripUpdatesFeed->upcomingStopTimes($feed)),
            $age->fresh ? 'frais' : 'périmé',
        ));

        return $age->fresh ? Command::SUCCESS : Command::FAILURE;
    }

    private function fail(OutputInterface $output, string $prefix, string $cause): int
    {
        $output->writeln($prefix.' : '.$cause);

        return Command::FAILURE;
    }
}
