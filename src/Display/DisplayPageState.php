<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Ce que la page d'affichage montre, prêt à mettre en page : ses écrans dans
 * l'ordre de rotation, ou un écran d'état à leur place.
 *
 * - « $stateMessage » remplace les écrans quand il n'est pas null : « Écran
 *   désactivé », ou un message vide pour l'écran « indisponible ».
 * - « $offline » : le flux temps réel n'a pas pu être lu.
 */
final readonly class DisplayPageState
{
    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    private function __construct(
        public array $screens,
        public ?string $stateMessage,
        public bool $offline,
    ) {
    }

    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    public static function screens(array $screens): self
    {
        return new self($screens, stateMessage: null, offline: false);
    }

    public static function disabled(): self
    {
        return new self([], stateMessage: 'Écran désactivé', offline: false);
    }

    public static function feedUnavailable(): self
    {
        return new self([], stateMessage: '', offline: true);
    }

    /**
     * Les variables des gabarits de la page d'affichage qui viennent de cet
     * état.
     *
     * @return array{screens: list<Screen>, state_message: ?string, offline: bool}
     */
    public function templateVariables(): array
    {
        return [
            'screens' => $this->screens,
            'state_message' => $this->stateMessage,
            'offline' => $this->offline,
        ];
    }
}
