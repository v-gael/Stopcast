<?php

declare(strict_types=1);

namespace App\Tests\Unit\Display;

use App\Display\Screen;
use App\Display\ScreenPaginator;
use App\Display\ScreenPassage;
use App\Dto\GtfsRt\DisplayItem;
use App\Dto\GtfsRt\NextPassage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ScreenPaginatorTest extends TestCase
{
    private const string NOW = '2026-09-28 08:00:00';

    public function testEachConfigurationGivesOneScreenPerFivePassages(): void
    {
        $screens = $this->paginate([
            new DisplayItem('Centre', 'CENTRE VILLE', $this->passages(7)),
            new DisplayItem(null, 'GARE', $this->passages(5)),
        ]);

        self::assertSame(['CENTRE VILLE', 'CENTRE VILLE', 'GARE'], array_map(static fn (Screen $screen): string => $screen->quayName, $screens));
        self::assertSame([5, 2, 5], array_map(static fn (Screen $screen): int => \count($screen->passages), $screens));
        self::assertSame(['1', '2', '3', '4', '5'], array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $screens[0]->passages));
        self::assertSame(['6', '7'], array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $screens[1]->passages));
    }

    public function testConfigurationWithoutPassageGivesTheNoBusScreen(): void
    {
        $screens = $this->paginate([new DisplayItem(null, 'GARE', [])]);

        self::assertCount(1, $screens);
        self::assertSame('GARE', $screens[0]->quayName);
        self::assertSame([], $screens[0]->passages);
    }

    public function testDeprecatedQuayWithoutPassageGivesNoScreen(): void
    {
        $screens = $this->paginate([
            new DisplayItem(null, 'DISPARU', [], quayDeprecated: true),
            new DisplayItem(null, 'GARE', $this->passages(1)),
        ]);

        self::assertSame(['GARE'], array_map(static fn (Screen $screen): string => $screen->quayName, $screens));
    }

    public function testDeprecatedQuayWhosePassagesAreAllGoneGivesNoScreen(): void
    {
        self::assertSame([], $this->paginate([new DisplayItem(null, 'DISPARU', [$this->passage('1', -1)], quayDeprecated: true)]));
    }

    public function testDeprecatedQuayStillAnnouncedKeepsItsScreens(): void
    {
        $screens = $this->paginate([new DisplayItem(null, 'DISPARU', [$this->passage('1', 2)], quayDeprecated: true)]);

        self::assertSame(['1'], array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $screens[0]->passages));
    }

    public function testNoActiveConfigurationGivesNoScreen(): void
    {
        self::assertSame([], $this->paginate([]));
    }

    public function testPassageAlreadyGoneIsNotShown(): void
    {
        $screens = $this->paginate([new DisplayItem(null, 'GARE', [
            $this->passage('1', -1),
            $this->passage('2', 0),
        ])]);

        self::assertSame(['2'], array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $screens[0]->passages));
    }

    public function testMinutesAreRoundedDown(): void
    {
        $screens = $this->paginate([new DisplayItem(null, 'GARE', [
            new NextPassage('1', 'FF5500', 'FFFFFF', 'GARE', new \DateTimeImmutable(self::NOW)->modify('+3 minutes 55 seconds')),
            new NextPassage('2', 'FF5500', 'FFFFFF', 'GARE', new \DateTimeImmutable(self::NOW)->modify('+59 seconds')),
        ])]);

        self::assertSame([3, 0], array_map(static fn (ScreenPassage $passage): int => $passage->minutes, $screens[0]->passages));
    }

    public function testInvalidColorsAreLeftToTheScreenDefaults(): void
    {
        $screens = $this->paginate([new DisplayItem(null, 'GARE', [
            new NextPassage('1', 'a020f0', '', null, new \DateTimeImmutable(self::NOW)->modify('+5 minutes')),
            new NextPassage('2', 'red', '#FFFFFF', 'GARE', new \DateTimeImmutable(self::NOW)->modify('+6 minutes')),
        ])]);

        [$first, $second] = $screens[0]->passages;
        self::assertSame(['A020F0', null, ''], [$first->routeColor, $first->routeTextColor, $first->headsign]);
        self::assertSame([null, null], [$second->routeColor, $second->routeTextColor]);
    }

    /**
     * @param list<DisplayItem> $items
     *
     * @return list<Screen>
     */
    private function paginate(array $items): array
    {
        return new ScreenPaginator(new MockClock(self::NOW, date_default_timezone_get()))->paginate($items);
    }

    /**
     * @return list<NextPassage> lignes « 1 » à « $count », une minute d'écart
     */
    private function passages(int $count): array
    {
        return array_map(fn (int $i): NextPassage => $this->passage((string) $i, $i), range(1, $count));
    }

    private function passage(string $route, int $minutes): NextPassage
    {
        return new NextPassage($route, 'FF5500', 'FFFFFF', 'GARE', new \DateTimeImmutable(self::NOW)->modify(\sprintf('%+d minutes', $minutes)));
    }
}
