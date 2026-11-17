<?php

declare(strict_types=1);

namespace App\Display;

use Symfony\Component\Clock\ClockInterface;

/**
 * Jeu de démo des écrans de la vitrine (page d'accueil), écrit ici et pas en
 * base : les vrais passages laisseraient la page vide la nuit. Relevé une fois
 * sur les horaires planifiés du réseau TCAT, un mardi à 8 h 50, sur les quais
 * les plus desservis des trois arrêts qui comptent le plus de passages ; le
 * deuxième porte un libellé, le troisième n'a volontairement aucun passage.
 * À mettre à jour à la main si les lignes changent.
 *
 * L'écran est figé à 8 h 50 du jour : les délais affichés restent ceux du
 * relevé, jamais « 0 min ».
 */
final readonly class ShowcaseScreens
{
    public const int HOUR = 8;

    public const int MINUTE = 50;

    /**
     * Titre de l'écran, puis ses passages : ligne, couleur de fond, couleur du
     * texte, destination, secondes après 8 h 50.
     *
     * @var list<array{string, list<array{string, string, string, string, int}>}>
     */
    private const array SCREENS = [
        ['BEURNONVILLE', [
            ['2', 'E20019', 'FFFFFF', 'BUCHERES PARC LOGISTIQUE', 128],
            ['4', 'F29400', '000000', 'HAMELET-DULOU', 277],
            ['N', 'CDDE8D', '000000', 'COEUR DE TROYES', 416],
            ['1', '009DE0', 'FFFFFF', 'CENTRE ADMINISTRATIF', 577],
            ['N', 'CDDE8D', '000000', 'COEUR DE TROYES', 896],
        ]],
        // Quai PATTON, vers le centre administratif.
        ['Direction travail', [
            ['N', 'CDDE8D', '000000', 'COEUR DE TROYES', 143],
            ['4', 'F29400', '000000', 'HAMELET-DULOU', 480],
            ['N', 'CDDE8D', '000000', 'COEUR DE TROYES', 623],
            ['5', '00509D', 'FFFFFF', 'FOUCHY', 720],
            ['1', '009DE0', 'FFFFFF', 'CENTRE ADMINISTRATIF', 780],
        ]],
        ['1ER RAM', []],
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * L'heure figée des écrans : 8 h 50 du jour.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(self::HOUR, self::MINUTE);
    }

    /**
     * @return list<Screen> dans l'ordre de rotation
     */
    public function all(): array
    {
        $now = $this->now();

        return array_map(
            static fn (array $screen): Screen => new Screen($screen[0], array_map(
                static fn (array $passage): ScreenPassage => new ScreenPassage(
                    routeShortName: $passage[0],
                    routeColor: $passage[1],
                    routeTextColor: $passage[2],
                    headsign: $passage[3],
                    expectedAt: $now->modify(\sprintf('+%d seconds', $passage[4])),
                    minutes: intdiv($passage[4], 60),
                ),
                $screen[1],
            )),
            self::SCREENS,
        );
    }
}
