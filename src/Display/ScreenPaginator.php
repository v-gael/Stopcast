<?php

declare(strict_types=1);

namespace App\Display;

use App\Dto\GtfsRt\DisplayItem;
use App\Dto\GtfsRt\NextPassage;
use Symfony\Component\Clock\ClockInterface;

/**
 * Découpe les configurations d'un dispositif en écrans de la page
 * d'affichage : une configuration donne un écran par tranche de
 * PASSAGES_PER_SCREEN passages, ou un seul écran « aucun passage ».
 */
final readonly class ScreenPaginator
{
    public const int PASSAGES_PER_SCREEN = 5;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<DisplayItem> $items dans l'ordre de rotation
     *
     * @return list<Screen> dans l'ordre de rotation ; vide sans configuration active
     */
    public function paginate(array $items): array
    {
        $now = $this->clock->now();
        $screens = [];

        foreach ($items as $item) {
            // Un passage tout juste dépassé n'est plus à annoncer.
            $passages = array_values(array_map(
                static fn (NextPassage $passage): ScreenPassage => self::screenPassage($passage, $now),
                array_filter($item->nextPassages, static fn (NextPassage $passage): bool => $passage->expectedAt >= $now),
            ));

            if ([] === $passages) {
                $screens[] = new Screen($item->quayName, []);

                continue;
            }

            foreach (array_chunk($passages, self::PASSAGES_PER_SCREEN) as $page) {
                $screens[] = new Screen($item->quayName, $page);
            }
        }

        return $screens;
    }

    private static function screenPassage(NextPassage $passage, \DateTimeImmutable $now): ScreenPassage
    {
        return new ScreenPassage(
            routeShortName: $passage->routeShortName,
            routeColor: self::color($passage->routeColor),
            routeTextColor: self::color($passage->routeTextColor),
            headsign: $passage->headsign ?? '',
            expectedAt: $passage->expectedAt,
            // Minutes entières restantes, arrondies à l'inférieur.
            minutes: intdiv($passage->expectedAt->getTimestamp() - $now->getTimestamp(), 60),
        );
    }

    private static function color(string $color): ?string
    {
        return 1 === preg_match('/^[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : null;
    }
}
