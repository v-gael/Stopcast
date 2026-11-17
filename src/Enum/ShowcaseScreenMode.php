<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État de départ d'un écran de la vitrine (paramètre « mode » de l'adresse).
 */
enum ShowcaseScreenMode: string
{
    /** Les écrans du jeu de démo tournent. */
    case Rotation = 'rotation';

    /** Le premier écran reste affiché. */
    case Still = 'fixe';

    /**
     * Écran du dispositif « Maison » du parcours « Comment ça marche »,
     * allumé et vide, qui attend les commandes de la page d'accueil : adresse
     * saisie, appairage, puis rotation sur ses quais.
     */
    case Driven = 'pilote';

    /** Le dispositif « Maison » du parcours, appairé : ses quais tournent. */
    case WalkthroughDevice = 'maison';

    /**
     * Les écrans du dispositif « Maison » du parcours, qui n'a que les deux
     * quais que le visiteur y ajoute.
     */
    public function isWalkthroughDevice(): bool
    {
        return self::Driven === $this || self::WalkthroughDevice === $this;
    }

    public function rotates(): bool
    {
        return self::Rotation === $this || self::WalkthroughDevice === $this;
    }
}
