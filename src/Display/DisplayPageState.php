<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Ce que la page d'affichage montre, prêt à mettre en page : ses écrans dans
 * l'ordre de rotation, ou un écran d'état à leur place.
 *
 * - « $stateMessage » remplace les écrans quand il n'est pas null : « Écran
 *   désactivé ».
 * - « $feedUnavailable » : le flux temps réel n'a pas pu être lu ; l'écran
 *   « indisponible » remplace les écrans.
 */
final readonly class DisplayPageState
{
    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    private function __construct(
        public array $screens,
        public ?string $stateMessage,
        public bool $feedUnavailable,
    ) {
    }

    /**
     * @param list<Screen> $screens dans l'ordre de rotation
     */
    public static function screens(array $screens): self
    {
        return new self($screens, stateMessage: null, feedUnavailable: false);
    }

    public static function disabled(): self
    {
        return new self([], stateMessage: 'Écran désactivé', feedUnavailable: false);
    }

    public static function feedUnavailable(): self
    {
        return new self([], stateMessage: null, feedUnavailable: true);
    }

    /**
     * Les variables des gabarits de la page d'affichage qui viennent de cet
     * état : « offline » y fait de l'écran d'état l'écran « indisponible ».
     *
     * @return array{screens: list<Screen>, state_message: ?string, offline: bool}
     */
    public function templateVariables(): array
    {
        return [
            'screens' => $this->screens,
            'state_message' => $this->stateMessage,
            'offline' => $this->feedUnavailable,
        ];
    }
}
