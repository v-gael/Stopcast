<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Contenu d'une archive GTFS statique, lu par StaticFeedReader et prêt à
 * être persisté.
 */
final readonly class StaticFeed
{
    /**
     * @param list<ParsedRoute>         $routes
     * @param list<ParsedQuay>          $quays
     * @param list<ParsedQuayRouteLink> $quayRouteLinks
     * @param list<ParsedTrip>          $trips
     */
    public function __construct(
        public array $routes,
        public array $quays,
        public array $quayRouteLinks,
        public array $trips,
    ) {
    }
}
