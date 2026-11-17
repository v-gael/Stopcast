<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Un écran de la page d'affichage : son titre (libellé de la configuration,
 * ou à défaut nom du quai) et au plus
 * ScreenPaginator::PASSAGES_PER_SCREEN passages. Sans passage, l'écran
 * « aucun passage ».
 */
final readonly class Screen
{
    /**
     * @param string              $title    libellé de la configuration, ou à défaut nom du quai
     * @param list<ScreenPassage> $passages
     */
    public function __construct(
        public string $title,
        public array $passages,
    ) {
    }
}
