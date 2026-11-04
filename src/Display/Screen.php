<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Un écran de la page d'affichage : un quai et au plus
 * ScreenPaginator::PASSAGES_PER_SCREEN passages. Sans passage, l'écran
 * « aucun passage ».
 */
final readonly class Screen
{
    /**
     * @param list<ScreenPassage> $passages
     */
    public function __construct(
        public string $quayName,
        public array $passages,
    ) {
    }
}
