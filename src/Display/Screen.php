<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Un écran de la page d'affichage : le nom affiché de sa configuration
 * (DisplayConfiguration::getDisplayName()) et au plus
 * ScreenPaginator::PASSAGES_PER_SCREEN passages. Sans passage, l'écran
 * « aucun passage ».
 */
final readonly class Screen
{
    /**
     * @param list<ScreenPassage> $passages
     */
    public function __construct(
        public string $title,
        public array $passages,
    ) {
    }
}
