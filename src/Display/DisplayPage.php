<?php

declare(strict_types=1);

namespace App\Display;

use App\Dto\GtfsRt\NextPassage;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Gtfs\Realtime\NextPassageFinder;
use Symfony\Component\Clock\ClockInterface;

/**
 * Ce que la page d'affichage d'un dispositif montre : sur les passages réels
 * pour l'écran lui-même (/display) et l'aperçu réel de la page du dispositif,
 * qui passent par ici pour montrer la même chose ; sur les passages d'exemple
 * pour les autres aperçus, sans lire l'index temps réel.
 *
 * Chaque configuration donne un seul écran, titré de son nom affiché
 * (DisplayConfiguration::getDisplayName()) : ses prochains passages, au plus
 * PASSAGES_PER_SCREEN, ou l'écran « aucun passage ». Un quai déprécié sans
 * passage n'a pas d'écran : il sort de la rotation.
 */
final readonly class DisplayPage
{
    /**
     * Passages d'un écran, et donc passages lus par quai (NextPassageFinder) :
     * autant que de lignes de la grille de l'écran (display.css).
     */
    public const int PASSAGES_PER_SCREEN = 5;

    public function __construct(
        private NextPassageFinder $nextPassageFinder,
        private SamplePassages $samplePassages,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Les configurations actives, sur les passages réels, lus dans l'index en
     * une fois pour tous les quais du dispositif. Un quai déprécié n'y reste
     * que s'il a encore des passages. Un dispositif désactivé ne lit pas
     * l'index des passages. Un index absent ou périmé n'est pas journalisé
     * ici : la panne du flux l'est une fois, par realtime-worker
     * (PassageIndexRefresher), et non par chaque écran.
     */
    public function forDevice(Device $device): DisplayPageState
    {
        if (!$device->isActive()) {
            return DisplayPageState::disabled();
        }

        $configurations = $device->getActiveDisplayConfigurations();

        try {
            // Sans les passages tenus pour partis (NextPassage::isStillExpectedAt()).
            $passagesByStopId = $this->nextPassageFinder->forQuays(
                array_map(static fn (DisplayConfiguration $configuration): Quay => $configuration->getQuay(), $configurations),
            );
        } catch (InvalidGtfsRealtimeFeedException) {
            return DisplayPageState::feedUnavailable();
        }

        return $this->state($configurations, static fn (DisplayConfiguration $configuration): array => $passagesByStopId[$configuration->getQuay()->getGtfsStopId()]);
    }

    /**
     * Toute la rotation du dispositif sur les passages d'exemple, qu'il soit
     * actif ou non : ses configurations affichées, sans les quais dépréciés.
     * Sur l'écran réel, un quai déprécié n'a en général plus de passage et
     * sort donc de la rotation, là où l'aperçu lui montrerait des passages
     * d'exemple.
     */
    public function sampleForDevice(Device $device): DisplayPageState
    {
        return $this->sampleState($device->getDisplayedConfigurations());
    }

    /**
     * Une seule configuration sur les passages d'exemple, même masquée ou sur
     * un quai déprécié.
     */
    public function sampleForConfiguration(DisplayConfiguration $configuration): DisplayPageState
    {
        return $this->sampleState([$configuration]);
    }

    /**
     * @param list<DisplayConfiguration> $configurations
     */
    private function sampleState(array $configurations): DisplayPageState
    {
        $samplePassages = $this->samplePassages->all();

        return $this->state($configurations, static fn (): array => $samplePassages);
    }

    /**
     * @param list<DisplayConfiguration>                    $configurations dans l'ordre de rotation
     * @param \Closure(DisplayConfiguration): NextPassage[] $passagesOf
     */
    private function state(array $configurations, \Closure $passagesOf): DisplayPageState
    {
        $now = $this->clock->now();
        $screens = [];

        foreach ($configurations as $configuration) {
            $passages = array_map(
                static fn (NextPassage $passage): ScreenPassage => self::screenPassage($passage, $now),
                // Pas plus de passages que de lignes dans la grille de l'écran, d'où qu'ils viennent.
                \array_slice(array_values($passagesOf($configuration)), 0, self::PASSAGES_PER_SCREEN),
            );

            if ([] !== $passages || $configuration->getQuay()->isActive()) {
                $screens[] = new Screen($configuration->getDisplayName(), $passages);
            }
        }

        return DisplayPageState::screens($screens);
    }

    private static function screenPassage(NextPassage $passage, \DateTimeImmutable $now): ScreenPassage
    {
        return new ScreenPassage(
            routeShortName: $passage->routeShortName,
            routeColor: self::color($passage->routeColor),
            routeTextColor: self::color($passage->routeTextColor),
            headsign: $passage->headsign ?? '',
            expectedAt: $passage->expectedAt,
            // Minutes entières restantes, arrondies à l'inférieur ; 0 pour un passage en retard encore attendu.
            minutes: max(0, intdiv($passage->expectedAt->getTimestamp() - $now->getTimestamp(), 60)),
        );
    }

    private static function color(string $color): ?string
    {
        return 1 === preg_match('/^[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : null;
    }
}
