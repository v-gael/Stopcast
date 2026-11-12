<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Ce que montre l'écran d'un dispositif sur les passages réels (LiveDisplay) :
 * ses écrans, l'écran « Écran désactivé », ou l'écran « indisponible » quand
 * le flux temps réel n'a pas pu être lu.
 */
final readonly class LiveDisplayState
{
    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    private function __construct(
        public array $screens,
        public bool $disabled,
        public bool $feedUnavailable,
    ) {
    }

    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    public static function screens(array $screens): self
    {
        return new self($screens, disabled: false, feedUnavailable: false);
    }

    public static function disabled(): self
    {
        return new self([], disabled: true, feedUnavailable: false);
    }

    public static function feedUnavailable(): self
    {
        return new self([], disabled: false, feedUnavailable: true);
    }
}
