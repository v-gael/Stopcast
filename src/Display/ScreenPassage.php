<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Un passage tel qu'une ligne d'écran le montre. Les couleurs sont des
 * hexadécimaux sans « # », ou null si celles du flux sont invalides : l'écran
 * prend alors ses couleurs par défaut.
 */
final readonly class ScreenPassage
{
    public function __construct(
        public string $routeShortName,
        public ?string $routeColor,
        public ?string $routeTextColor,
        public string $headsign,
        public \DateTimeImmutable $expectedAt,
        public int $minutes,
    ) {
    }
}
