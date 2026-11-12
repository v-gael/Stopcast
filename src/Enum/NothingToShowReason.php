<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi un dispositif n'a aucun quai à afficher, dans l'ordre des cas.
 */
enum NothingToShowReason
{
    /** Aucune configuration. */
    case NoQuay;

    /** Toutes les configurations sont masquées. */
    case AllHidden;

    /** Toutes les configurations affichées ont un quai disparu du dernier import. */
    case AllQuaysGone;

    public function explanation(): string
    {
        return match ($this) {
            self::NoQuay => 'Aucun quai configuré.',
            self::AllHidden => 'Tous les quais sont masqués.',
            self::AllQuaysGone => 'Tous les quais affichés ont disparu du dernier import.',
        };
    }
}
