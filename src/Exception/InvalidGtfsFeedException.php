<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levée par GtfsService::validateFeedContents() quand l'archive
 * téléchargée est illisible ou qu'il manque un fichier GTFS attendu.
 */
class InvalidGtfsFeedException extends \RuntimeException
{
    public static function missingFile(string $filename): self
    {
        return new self(\sprintf('Fichier GTFS attendu manquant : "%s".', $filename));
    }

    public static function emptyFile(string $filename): self
    {
        return new self(\sprintf('Fichier GTFS vide ou illisible : "%s".', $filename));
    }

    public static function corruptedArchive(string $path): self
    {
        return new self(\sprintf('Archive GTFS illisible ou corrompue : "%s".', $path));
    }
}
