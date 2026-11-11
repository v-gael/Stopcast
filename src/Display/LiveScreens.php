<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Écrans d'un dispositif sur les passages réels (LiveDisplay), prêts pour la
 * page d'affichage.
 */
final readonly class LiveScreens
{
    /**
     * @param list<Screen> $screens         dans l'ordre de rotation
     * @param string|null  $stateMessage    écran d'état à la place des écrans ; null : les écrans
     * @param bool         $feedUnavailable le flux temps réel n'a pas pu être lu
     */
    public function __construct(
        public array $screens,
        public ?string $stateMessage,
        public bool $feedUnavailable,
    ) {
    }
}
