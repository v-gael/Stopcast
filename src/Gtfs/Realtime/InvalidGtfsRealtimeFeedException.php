<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

/**
 * Levée quand la réponse du flux temps réel n'est pas un FeedMessage valide
 * (page d'erreur renvoyée avec un statut 200, corps vide ou tronqué). Une
 * telle réponse n'est jamais mise en cache.
 */
class InvalidGtfsRealtimeFeedException extends \RuntimeException
{
    public static function undecodable(string $url, \Throwable $previous): self
    {
        return new self(\sprintf('Flux GTFS-RT illisible : "%s".', $url), previous: $previous);
    }

    public static function missingHeader(string $url): self
    {
        return new self(\sprintf('Flux GTFS-RT sans header, flux invalide : "%s".', $url));
    }
}
