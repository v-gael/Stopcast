<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Levée par StaticFeedReader quand l'archive téléchargée est illisible ou
 * qu'il manque un fichier GTFS attendu.
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

    public static function corruptedArchive(): self
    {
        return new self('Archive GTFS illisible ou corrompue.');
    }
}
