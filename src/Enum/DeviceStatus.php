<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État d'un dispositif actif qui demande l'attention de son propriétaire.
 */
enum DeviceStatus
{
    /** Aucun écran n'est appairé au dispositif. */
    case Unpaired;

    /** L'écran appairé ne donne plus de nouvelles. */
    case Offline;

    public function label(): string
    {
        return match ($this) {
            self::Unpaired => 'Non appairé',
            self::Offline => 'Hors ligne',
        };
    }
}
