<?php

declare(strict_types=1);

namespace App\Display;

use App\Dto\GtfsRt\DisplayItem;
use App\Dto\GtfsRt\NextPassage;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Gtfs\Realtime\NextPassageFinder;

/**
 * Assemble ce qu'un dispositif affiche : une entrée par configuration active,
 * dans l'ordre de rotation, avec ses prochains passages.
 */
final readonly class DisplayAssembler
{
    public function __construct(
        private NextPassageFinder $nextPassageFinder,
        private SamplePassages $samplePassages,
    ) {
    }

    /**
     * Passages réels, lus dans l'index temps réel. Un seul appel pour tous les
     * quais du dispositif : l'index n'est lu qu'une fois. Si l'index est absent
     * ou périmé, l'exception remonte telle quelle plutôt qu'une réponse
     * partielle ou vide : l'appelant garde ce qu'il affichait.
     *
     * @return list<DisplayItem>
     *
     * @throws InvalidGtfsRealtimeFeedException index absent, ou flux périmé
     */
    public function forDevice(Device $device): array
    {
        $configurations = $device->getActiveDisplayConfigurations();

        $passagesByStopId = $this->nextPassageFinder->forQuays(
            array_map(static fn (DisplayConfiguration $configuration): Quay => $configuration->getQuay(), $configurations),
        );

        return array_map(
            static fn (DisplayConfiguration $configuration): DisplayItem => self::item($configuration, $passagesByStopId[$configuration->getQuay()->getGtfsStopId()]),
            $configurations,
        );
    }

    /**
     * Les configurations affichées, sur les passages d'exemple
     * (DisplayPage::sampleForDevice()).
     *
     * @return list<DisplayItem>
     */
    public function sampleForDevice(Device $device): array
    {
        return array_map($this->sampleForConfiguration(...), $device->getDisplayedConfigurations());
    }

    /**
     * Une configuration seule sur les passages d'exemple, qu'elle soit
     * affichée ou masquée.
     */
    public function sampleForConfiguration(DisplayConfiguration $configuration): DisplayItem
    {
        return self::item($configuration, $this->samplePassages->all());
    }

    /**
     * @param NextPassage[] $nextPassages
     */
    private static function item(DisplayConfiguration $configuration, array $nextPassages): DisplayItem
    {
        return new DisplayItem(
            displayName: $configuration->getDisplayName(),
            nextPassages: $nextPassages,
            quayDeprecated: !$configuration->getQuay()->isActive(),
        );
    }
}
