<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

/**
 * Levée quand la réponse du flux temps réel n'est pas un FeedMessage valide
 * (page d'erreur renvoyée avec un statut 200, corps vide ou tronqué), jamais
 * mise en cache, ou quand le flux est périmé (header.timestamp au-delà de
 * GTFS_REALTIME_MAX_AGE) : le producteur sert un flux figé, dont les passages
 * ne bougent plus. Un flux périmé, lisible, est mis en cache comme les autres.
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

    public static function stale(string $url, int $age, int $maxAge): self
    {
        return new self(\sprintf('Flux GTFS-RT périmé : "%s" (âge %d s, maximum %d s).', $url, $age, $maxAge));
    }
}
