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
     * Écran allumé et vide, qui attend les commandes de la page d'accueil
     * (parcours « Comment ça marche ») : adresse saisie, appairage, rotation.
     */
    case Driven = 'pilote';
}
