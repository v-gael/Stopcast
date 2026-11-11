<?php

declare(strict_types=1);

namespace App\Display;

use App\Entity\Device;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Monitoring\RealtimeFeedFailureLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

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
        private RealtimeFeedFailureLogger $feedFailureLogger,
    ) {
    }

    /**
     * Un dispositif désactivé donne l'écran « Écran désactivé », sans lire le
     * flux. Un flux en échec est journalisé et donne un état sans écran.
     */
    public function forDevice(Device $device): LiveScreens
    {
        if (!$device->isActive()) {
            return new LiveScreens([], 'Écran désactivé', feedUnavailable: false);
        }

        try {
            return new LiveScreens($this->screenPaginator->paginate($this->displayAssembler->forDevice($device)), null, feedUnavailable: false);
        } catch (HttpClientExceptionInterface|InvalidGtfsRealtimeFeedException $failure) {
            $this->feedFailureLogger->log($failure);

            // Message vide : l'écran « indisponible » remplace les écrans.
            return new LiveScreens([], '', feedUnavailable: true);
        }
    }
}
