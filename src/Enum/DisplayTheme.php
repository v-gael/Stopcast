<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Couleurs de l'écran d'un dispositif. Les palettes vivent dans le CSS
 * (assets/styles/display.css et device_bench.css), sous l'attribut
 * data-theme qui porte la valeur.
 */
enum DisplayTheme: string
{
    /** Fond bleu nuit, pour les pièces peu éclairées : les couleurs du client Python. */
    case Dark = 'dark';

    /** Fond clair, pour les pièces lumineuses. */
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::Dark => 'Sombre',
            self::Light => 'Clair',
        };
    }
}
