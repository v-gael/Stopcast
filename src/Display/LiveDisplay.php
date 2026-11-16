<?php

declare(strict_types=1);

namespace App\Display;

use App\Entity\Device;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;

/**
 * Ce que l'écran d'un dispositif montre en ce moment, sur les passages réels :
 * l'écran lui-même (/display) et l'aperçu de la page du dispositif passent par
 * ici, pour montrer la même chose.
 */
final readonly class LiveDisplay
{
    public function __construct(
        private DisplayAssembler $displayAssembler,
        private ScreenPaginator $screenPaginator,
    ) {
    }

    /**
     * Un dispositif désactivé ne lit pas l'index des passages. Un index absent
     * ou périmé n'est pas journalisé ici : la panne du flux l'est une fois, par
     * realtime-worker (PassageIndexRefresher), et non par chaque écran.
     */
    public function forDevice(Device $device): LiveDisplayState
    {
        if (!$device->isActive()) {
            return LiveDisplayState::disabled();
        }

        try {
            return LiveDisplayState::screens($this->screenPaginator->paginate($this->displayAssembler->forDevice($device)));
        } catch (InvalidGtfsRealtimeFeedException) {
            return LiveDisplayState::feedUnavailable();
        }
    }
}
