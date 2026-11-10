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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

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
     * Passages réels, tirés du flux temps réel. Un seul appel pour tous les
     * quais du dispositif : le flux n'est lu qu'une fois. Si le flux échoue,
     * l'exception remonte telle quelle plutôt qu'une réponse partielle ou vide :
     * l'appelant garde ce qu'il affichait.
     *
     * @return list<DisplayItem>
     *
     * @throws InvalidGtfsRealtimeFeedException flux illisible ou périmé
     * @throws HttpClientExceptionInterface     téléchargement impossible
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
     * Mêmes écrans que forDevice, sur les passages d'exemple : pour l'aperçu,
     * sans appel au flux temps réel. Un quai déprécié en est exclu : sans
     * passage annoncé, cas courant, l'écran réel le saute.
     *
     * @return list<DisplayItem>
     */
    public function sampleForDevice(Device $device): array
    {
        return array_values(array_map(
            $this->sampleForConfiguration(...),
            array_filter(
                $device->getActiveDisplayConfigurations(),
                static fn (DisplayConfiguration $configuration): bool => $configuration->getQuay()->isActive(),
            ),
        ));
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
            label: $configuration->getLabel(),
            quayName: $configuration->getQuay()->getName(),
            nextPassages: $nextPassages,
            quayDeprecated: !$configuration->getQuay()->isActive(),
        );
    }
}
