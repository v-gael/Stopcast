<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Lit une archive GTFS statique et la traduit en StaticFeed, sans toucher à
 * la base : tout le format GTFS (colonnes optionnelles, valeurs par défaut,
 * lignes malformées) est connu ici et nulle part ailleurs.
 *
 * Les fichiers sont lus en flux directement dans l'archive, sans extraction,
 * et chacun une seule fois : stop_times.txt peut atteindre plusieurs
 * centaines de milliers de lignes.
 */
final class StaticFeedReader
{
    private const array REQUIRED_FILES = ['routes.txt', 'stops.txt', 'trips.txt', 'stop_times.txt'];

    /**
     * @throws InvalidGtfsFeedException archive illisible, fichier obligatoire absent ou vide
     */
    public function read(string $archivePath): StaticFeed
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archivePath)) {
            throw InvalidGtfsFeedException::corruptedArchive();
        }

        try {
            $this->validate($zip);

            $trips = $this->readTrips($zip);
            [$terminusStopIds, $quayRouteLinks] = $this->readStopTimes($zip, $trips);

            $parsedTrips = [];
            foreach ($trips as $gtfsTripId => $trip) {
                $parsedTrips[] = new ParsedTrip(
                    gtfsTripId: (string) $gtfsTripId,
                    gtfsRouteId: $trip['routeId'],
                    headsign: $trip['headsign'],
                    terminusStopId: $terminusStopIds[$gtfsTripId] ?? null,
                );
            }

            return new StaticFeed(
                routes: $this->readRoutes($zip),
                quays: $this->readQuays($zip),
                quayRouteLinks: $quayRouteLinks,
                trips: $parsedTrips,
            );
        } finally {
            $zip->close();
        }
    }

    private function validate(\ZipArchive $zip): void
    {
        foreach (self::REQUIRED_FILES as $filename) {
            $stat = $zip->statName($filename);

            if (false === $stat) {
                throw InvalidGtfsFeedException::missingFile($filename);
            }

            if (0 === $stat['size']) {
                throw InvalidGtfsFeedException::emptyFile($filename);
            }
        }
    }

    /**
     * @return list<ParsedRoute>
     */
    private function readRoutes(\ZipArchive $zip): array
    {
        $routes = [];

        foreach ($this->readCsv($zip, 'routes.txt') as $row) {
            $routes[] = new ParsedRoute(
                gtfsRouteId: $row['route_id'],
                shortName: $row['route_short_name'],
                longName: $this->nonEmpty($row['route_long_name'] ?? ''),
                // Valeurs par défaut de la spec GTFS quand la couleur est absente ou vide.
                color: $this->nonEmpty($row['route_color'] ?? '') ?? 'FFFFFF',
                textColor: $this->nonEmpty($row['route_text_color'] ?? '') ?? '000000',
            );
        }

        return $routes;
    }

    /**
     * Ne retient que les quais (location_type=0). Les arrêts physiques
     * (location_type=1) ne sont pas persistés en tant que tels, ils ne
     * servent qu'à renseigner parentStopId.
     *
     * @return list<ParsedQuay>
     */
    private function readQuays(\ZipArchive $zip): array
    {
        $quays = [];

        foreach ($this->readCsv($zip, 'stops.txt') as $row) {
            // Un location_type vide vaut 0 (quai) selon la spec GTFS.
            if (!\in_array($row['location_type'] ?? '', ['', '0'], true)) {
                continue;
            }

            $quays[] = new ParsedQuay(
                gtfsStopId: $row['stop_id'],
                name: $row['stop_name'],
                latitude: (float) $row['stop_lat'],
                longitude: (float) $row['stop_lon'],
                parentStopId: $row['parent_station'] ?? '',
            );
        }

        return $quays;
    }

    /**
     * @return array<string, array{routeId: string, headsign: string|null}> indexé par trip_id
     */
    private function readTrips(\ZipArchive $zip): array
    {
        $trips = [];

        foreach ($this->readCsv($zip, 'trips.txt') as $row) {
            $trips[$row['trip_id']] = [
                'routeId' => $row['route_id'],
                'headsign' => $this->nonEmpty($row['trip_headsign'] ?? ''),
            ];
        }

        return $trips;
    }

    /**
     * Un seul passage sur stop_times.txt pour en tirer :
     * - le terminus de chaque trajet (arrêt du plus grand stop_sequence),
     *   où ses passages ne sont pas affichés ;
     * - les lignes qui desservent chaque quai et leurs destinations
     *   distinctes. Un trajet sans destination lie sa ligne au quai sans
     *   ajouter de destination.
     *
     * @param array<string, array{routeId: string, headsign: string|null}> $trips
     *
     * @return array{array<string, string>, list<ParsedQuayRouteLink>} terminus indexés par trip_id, liens quai ↔ ligne
     */
    private function readStopTimes(\ZipArchive $zip, array $trips): array
    {
        // trip_id => [stop_sequence, stop_id] du plus grand stop_sequence vu.
        // stop_times.txt n'est pas forcément trié : comparaison numérique.
        $lastStopByTripId = [];
        // "stopId|routeId" => Set des destinations distinctes
        $destinationsByStopAndRoute = [];

        foreach ($this->readCsv($zip, 'stop_times.txt') as $row) {
            $trip = $trips[$row['trip_id']] ?? null;

            // Horaire d'un trajet absent de trips.txt : ni terminus ni lien à en tirer.
            if (null === $trip) {
                continue;
            }

            $sequence = (int) $row['stop_sequence'];

            if ($sequence >= ($lastStopByTripId[$row['trip_id']][0] ?? \PHP_INT_MIN)) {
                $lastStopByTripId[$row['trip_id']] = [$sequence, $row['stop_id']];
            }

            $key = $row['stop_id'].'|'.$trip['routeId'];
            $destinationsByStopAndRoute[$key] ??= [];

            if (null !== $trip['headsign']) {
                $destinationsByStopAndRoute[$key][$trip['headsign']] = true;
            }
        }

        $quayRouteLinks = [];
        foreach ($destinationsByStopAndRoute as $key => $destinations) {
            [$gtfsStopId, $gtfsRouteId] = explode('|', $key, 2);

            $quayRouteLinks[] = new ParsedQuayRouteLink(
                gtfsStopId: $gtfsStopId,
                gtfsRouteId: $gtfsRouteId,
                destinations: array_map(strval(...), array_keys($destinations)),
            );
        }

        return [
            array_map(static fn (array $lastStop): string => $lastStop[1], $lastStopByTripId),
            $quayRouteLinks,
        ];
    }

    /**
     * Lit un fichier CSV de l'archive ligne par ligne (en flux, sans le
     * charger en mémoire), en associant chaque ligne aux en-têtes.
     *
     * @return \Generator<array<string, string>>
     */
    private function readCsv(\ZipArchive $zip, string $filename): \Generator
    {
        $handle = $zip->getStream($filename);

        if (false === $handle) {
            throw InvalidGtfsFeedException::emptyFile($filename);
        }

        try {
            // Un flux d'archive ne peut pas revenir en arrière : l'éventuel BOM
            // UTF-8 est retiré en reconstituant la ligne d'en-têtes.
            $start = (string) fread($handle, 3);
            $headerLine = ("\xEF\xBB\xBF" === $start ? '' : $start).fgets($handle);

            if ('' === trim($headerLine)) {
                return;
            }

            $headers = array_map(
                static fn (?string $value): string => $value ?? '',
                str_getcsv(rtrim($headerLine, "\r\n"), escape: ''),
            );

            while (false !== ($row = fgetcsv($handle, escape: ''))) {
                if ([null] === $row) {
                    continue;
                }

                $row = array_map(static fn (?string $value): string => $value ?? '', $row);

                // Aligne la ligne sur les en-têtes (virgule finale, ligne tronquée) :
                // champs manquants complétés à vide, champs en trop ignorés.
                $row = \array_slice(array_pad($row, \count($headers), ''), 0, \count($headers));

                yield array_combine($headers, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    private function nonEmpty(string $value): ?string
    {
        return '' !== $value ? $value : null;
    }
}
