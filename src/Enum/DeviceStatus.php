<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État résumé d'un dispositif dans l'espace web : un seul par dispositif, le
 * premier qui s'applique dans l'ordre des cas.
 */
enum DeviceStatus
{
    /** Le dispositif est désactivé : son écran affiche « Écran désactivé ». */
    case Disabled;

    /** Aucun écran n'est appairé au dispositif. */
    case Unpaired;

    /** L'écran appairé n'a encore jamais donné de nouvelles. */
    case NeverSeen;

    /** L'écran appairé ne donne plus de nouvelles. */
    case Offline;

    /** Aucun quai à afficher : aucun configuré, tous masqués, ou disparus du dernier import. */
    case NothingToShow;

    /** Un quai au moins, affiché ou masqué, a disparu du dernier import. */
    case QuayGone;

    /** L'écran donne des nouvelles et a des quais à afficher. */
    case Online;

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Désactivé',
            self::Unpaired => 'Non appairé',
            self::NeverSeen => 'Jamais connecté',
            self::Offline => 'Hors ligne',
            self::NothingToShow => 'Rien à afficher',
            self::QuayGone => 'Quai disparu',
            self::Online => 'En ligne',
        };
    }

    /**
     * Tonalité de la pastille : neutral, warning, danger ou success.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Disabled, self::Unpaired => 'neutral',
            self::NeverSeen, self::NothingToShow, self::QuayGone => 'warning',
            self::Offline => 'danger',
            self::Online => 'success',
        };
    }
}
