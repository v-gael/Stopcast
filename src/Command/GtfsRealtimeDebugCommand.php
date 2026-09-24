<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RouteRepository;
use App\Service\GtfsRealtimeFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DEBUG UNIQUEMENT — à supprimer une fois le souci diagnostiqué.
 *
 * Interroge le flux GTFS-RT en direct (pas de mock, pas de cache contourné)
 * et affiche tout ce qu'il contient pour un stop_id donné, pour distinguer
 * "vraiment aucun bus prévu" d'un problème de mapping.
 */
#[AsCommand(
    name: 'app:gtfs:rt-debug',
    description: '[DEBUG] Inspecte le flux GTFS-RT en direct pour un stop_id donné',
)]
final class GtfsRealtimeDebugCommand extends Command
{
    public function __construct(
        private readonly GtfsRealtimeFetcher $fetcher,
        private readonly RouteRepository $routeRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('stopId', InputArgument::REQUIRED, 'gtfsStopId à inspecter, ex: 0:MAI02');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $stopId */
        $stopId = $input->getArgument('stopId');

        $io->title(\sprintf('Debug flux GTFS-RT pour stop_id="%s"', $stopId));

        $feed = $this->fetcher->fetchTripUpdates();

        $header = $feed->getHeader() ?? throw new \RuntimeException('Flux GTFS-RT sans header, flux invalide.');
        $timestamp = (int) $header->getTimestamp();

        $io->section('Header du flux');
        $io->table(['Champ', 'Valeur'], [
            ['gtfs_realtime_version', $header->getGtfsRealtimeVersion()],
            ['timestamp', $timestamp.' ('.date('Y-m-d H:i:s', $timestamp).')'],
            ['nombre d\'entités total', (string) \count($feed->getEntity())],
        ]);

        $tripUpdateCount = 0;
        $distinctStopIds = [];
        $matches = [];

        foreach ($feed->getEntity() as $entity) {
            $tripUpdate = $entity->getTripUpdate();

            if (null === $tripUpdate) {
                continue;
            }

            ++$tripUpdateCount;
            $trip = $tripUpdate->getTrip();

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                $distinctStopIds[$stopTimeUpdate->getStopId()] = true;

                if ($stopTimeUpdate->getStopId() !== $stopId) {
                    continue;
                }

                $routeId = $trip?->getRouteId();
                $route = null !== $routeId ? $this->routeRepository->findOneByGtfsRouteId($routeId) : null;

                $event = $stopTimeUpdate->hasDeparture() ? $stopTimeUpdate->getDeparture() : $stopTimeUpdate->getArrival();
                $time = (int) ($event?->getTime() ?? 0);

                $matches[] = [
                    'trip_id' => $trip?->getTripId(),
                    'route_id (flux)' => $routeId,
                    'Route trouvée en base ?' => $route instanceof \App\Entity\Route ? 'OUI ('.$route->getShortName().')' : 'NON — pas de match findOneByGtfsRouteId',
                    'time' => 0 === $time ? '(absent/0)' : date('Y-m-d H:i:s', $time),
                    'dans le futur ?' => $time > time() ? 'OUI' : 'NON (passé ou absent)',
                ];
            }
        }

        $io->section('Entités trip_update dans le flux');
        $io->text(\sprintf('%d entité(s) trip_update sur %d entité(s) total', $tripUpdateCount, \count($feed->getEntity())));

        $io->section(\sprintf('Correspondances trouvées pour stop_id="%s"', $stopId));

        if ([] === $matches) {
            $io->warning('Aucune stop_time_update ne correspond à ce stop_id dans le flux actuel.');

            // Aide au diagnostic : le stop_id existe-t-il seulement ailleurs, avec une casse/format différent ?
            $similar = array_filter(
                array_keys($distinctStopIds),
                static fn (string $id): bool => str_contains(strtolower($id), strtolower(substr($stopId, 2))),
            );

            if ([] !== $similar) {
                $io->text('Stop_id similaires trouvés dans le flux (vérifie la casse/le format) :');
                $io->listing($similar);
            } else {
                $io->text('Aucun stop_id ressemblant trouvé non plus — ce quai n\'est peut-être simplement pas desservi en ce moment.');
            }
        } else {
            $io->table(array_keys($matches[0]), array_map('array_values', $matches));
        }

        return Command::SUCCESS;
    }
}
