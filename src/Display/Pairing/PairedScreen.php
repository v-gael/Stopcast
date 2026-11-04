<?php

declare(strict_types=1);

namespace App\Display\Pairing;

use App\Entity\Device;

/**
 * Écran dont le code vient d'être saisi : le secret de son cookie, généré à
 * l'instant, dont seul le haché est enregistré sur le dispositif.
 */
final readonly class PairedScreen
{
    public function __construct(
        public Device $device,
        #[\SensitiveParameter]
        public string $screenSecret,
    ) {
    }
}
