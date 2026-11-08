<?php

declare(strict_types=1);

namespace App\Display;

use App\Dto\GtfsRt\NextPassage;
use Symfony\Component\Clock\ClockInterface;

/**
 * Passages fictifs de l'aperçu, écrits ici et pas en base : les mêmes pour
 * toute configuration, de quoi remplir un écran complet. Lignes et destinations
 * sont génériques, sans lien avec un réseau réel. Seules les heures suivent
 * l'horloge, pour que l'écran annonce toujours les mêmes délais.
 */
final readonly class SamplePassages
{
    /**
     * Ligne, couleur de fond, couleur du texte, destination, délai en minutes.
     *
     * @var list<array{string, string, string, string, int}>
     */
    private const array PASSAGES = [
        ['1', 'E2001A', 'FFFFFF', 'GARE CENTRALE', 2],
        ['12', '0076BF', 'FFFFFF', 'HÔPITAL NORD - PARC DES EXPOSITIONS', 4],
        ['A', 'F29400', '000000', 'CAMPUS UNIVERSITAIRE', 7],
        ['5', '009036', 'FFFFFF', 'HÔTEL DE VILLE', 11],
        ['N2', '7A3E9D', 'FFFFFF', 'ZONE ARTISANALE DES PRÉS - CENTRE COMMERCIAL', 18],
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<NextPassage>
     */
    public function all(): array
    {
        $now = $this->clock->now();

        return array_map(
            // Une demi-minute de plus : arrondi à la minute inférieure, le délai affiché est bien celui annoncé.
            static fn (array $passage): NextPassage => new NextPassage(
                routeShortName: $passage[0],
                routeColor: $passage[1],
                routeTextColor: $passage[2],
                headsign: $passage[3],
                expectedAt: $now->modify(\sprintf('+%d seconds', $passage[4] * 60 + 30)),
            ),
            self::PASSAGES,
        );
    }
}
