<?php

declare(strict_types=1);

namespace App\Display;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;

/**
 * Ce que la page d'affichage d'un dispositif montre : sur les passages réels
 * pour l'écran lui-même (/display) et l'aperçu réel de la page du dispositif,
 * qui passent par ici pour montrer la même chose ; sur les passages d'exemple
 * pour les autres aperçus, sans lire l'index temps réel.
 */
final readonly class DisplayPage
{
    public function __construct(
        private DisplayAssembler $displayAssembler,
        private ScreenPaginator $screenPaginator,
    ) {
    }

    /**
     * Les configurations actives, sur les passages réels. Un quai déprécié n'y
     * reste que s'il a encore des passages. Un dispositif désactivé ne lit pas
     * l'index des passages. Un index absent ou périmé n'est pas journalisé
     * ici : la panne du flux l'est une fois, par realtime-worker
     * (PassageIndexRefresher), et non par chaque écran.
     */
    public function forDevice(Device $device): DisplayPageState
    {
        if (!$device->isActive()) {
            return DisplayPageState::disabled();
        }

        try {
            return DisplayPageState::screens($this->screenPaginator->paginate($this->displayAssembler->forDevice($device)));
        } catch (InvalidGtfsRealtimeFeedException) {
            return DisplayPageState::feedUnavailable();
        }
    }

    /**
     * Toute la rotation du dispositif sur les passages d'exemple, qu'il soit
     * actif ou non : ses configurations actives, sans les quais dépréciés. Sur
     * l'écran réel, un quai déprécié n'a en général plus de passage et sort
     * donc de la rotation, là où l'aperçu lui montrerait des passages
     * d'exemple.
     */
    public function sampleForDevice(Device $device): DisplayPageState
    {
        return DisplayPageState::screens($this->screenPaginator->paginate($this->displayAssembler->sampleForDevice($device)));
    }

    /**
     * Une seule configuration sur les passages d'exemple, même masquée ou sur
     * un quai déprécié.
     */
    public function sampleForConfiguration(DisplayConfiguration $configuration): DisplayPageState
    {
        return DisplayPageState::screens($this->screenPaginator->paginate([$this->displayAssembler->sampleForConfiguration($configuration)]));
    }
}
