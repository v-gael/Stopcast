<?php

declare(strict_types=1);

namespace App\Tests\Unit\Display;

use App\Display\DisplayPage;
use App\Display\DisplayPageState;
use App\Display\SamplePassages;
use App\Display\Screen;
use App\Display\ScreenPassage;
use App\Dto\GtfsRt\NextPassage;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Gtfs\Realtime\NextPassageFinder;
use App\Gtfs\Realtime\PassageIndex;
use App\Gtfs\Realtime\PassageIndexStore;
use App\Gtfs\Realtime\TripUpdatesFeed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Ce que la page d'affichage d'un dispositif montre, sur les passages réels,
 * lus dans un index des passages gardé en mémoire, et sur les passages
 * d'exemple, à une heure fixée.
 */
final class DisplayPageTest extends TestCase
{
    private const string NOW = '2026-11-24 08:00:00';

    /** Lignes des passages d'exemple (SamplePassages), dans l'ordre. */
    private const array SAMPLE_ROUTES = ['1', '12', 'A', '5', 'N2'];

    /** Âge maximal du flux, en secondes (GTFS_REALTIME_MAX_AGE). */
    private const int MAX_AGE = 300;

    private MockClock $clock;

    private PassageIndexStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->store = new PassageIndexStore(new ArrayAdapter());
    }

    public function testEachDisplayedQuayGivesAScreenOfItsPassages(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->configure($device, 'S2', label: 'Centre');
        $this->index(['S1' => [$this->passage('1', 3), $this->passage('12', 8)], 'S2' => [$this->passage('A', 5)]]);

        $state = $this->displayPage()->forDevice($device);

        self::assertSame(['Gare', 'Centre'], $this->titles($state->screens));
        self::assertSame(['1', '12'], $this->routes($state->screens[0]));
        self::assertSame([3, 8], array_map(static fn (ScreenPassage $passage): int => $passage->minutes, $state->screens[0]->passages));
        self::assertNull($state->stateMessage);
        self::assertFalse($state->feedUnavailable);
    }

    public function testHiddenConfigurationIsLeftOutOfTheRotation(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->configure($device, 'S2', label: 'Masquée', active: false);
        $this->index(['S1' => [$this->passage('1', 3)], 'S2' => [$this->passage('A', 5)]]);

        self::assertSame(['Gare'], $this->titles($this->displayPage()->forDevice($device)->screens));
    }

    public function testQuayWithoutPassageGivesTheNoBusScreen(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index([]);

        $state = $this->displayPage()->forDevice($device);

        self::assertSame(['Gare'], $this->titles($state->screens));
        self::assertSame([], $state->screens[0]->passages);
    }

    public function testDeprecatedQuayWithoutPassageLeavesTheRotation(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Disparu', deprecated: true);
        $this->configure($device, 'S2', label: 'Gare');
        $this->index(['S2' => [$this->passage('1', 3)]]);

        self::assertSame(['Gare'], $this->titles($this->displayPage()->forDevice($device)->screens));
    }

    public function testDeprecatedQuayStillAnnouncedKeepsItsScreen(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Disparu', deprecated: true);
        $this->index(['S1' => [$this->passage('1', 3)]]);

        self::assertSame(['Disparu'], $this->titles($this->displayPage()->forDevice($device)->screens));
    }

    public function testDisabledDeviceShowsItsStateScreenWithoutReadingTheIndex(): void
    {
        $device = $this->device()->setActive(false);
        $this->configure($device, 'S1', label: 'Gare');

        $state = $this->displayPage()->forDevice($device);

        self::assertSame([], $state->screens);
        self::assertSame('Écran désactivé', $state->stateMessage);
        self::assertFalse($state->feedUnavailable);
    }

    public function testMissingIndexShowsTheUnavailableScreen(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');

        $this->assertUnavailable($this->displayPage()->forDevice($device));
    }

    public function testStaleFeedShowsTheUnavailableScreen(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index(['S1' => [$this->passage('1', 3)]], ageSeconds: self::MAX_AGE + 1);

        $this->assertUnavailable($this->displayPage()->forDevice($device));
    }

    public function testFeedJustWithinItsMaximumAgeIsStillShown(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index(['S1' => [$this->passage('1', 3)]], ageSeconds: self::MAX_AGE);

        self::assertSame(['1'], $this->routes($this->displayPage()->forDevice($device)->screens[0]));
    }

    public function testStateGivesTheVariablesOfTheDisplayTemplates(): void
    {
        $device = $this->device()->setActive(false);

        self::assertSame(
            ['screens' => [], 'state_message' => 'Écran désactivé', 'offline' => false],
            $this->displayPage()->forDevice($device)->templateVariables(),
        );
    }

    public function testSamplePreviewOfADeviceShowsTheSamplePassagesWithoutReadingTheIndex(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->configure($device, 'S2', label: 'Centre');

        $state = $this->displayPage()->sampleForDevice($device);

        self::assertSame(['Gare', 'Centre'], $this->titles($state->screens));
        self::assertSame(self::SAMPLE_ROUTES, $this->routes($state->screens[0]));
        self::assertNull($state->stateMessage);
        self::assertFalse($state->feedUnavailable);
    }

    public function testSamplePreviewOfADeviceLeavesOutHiddenConfigurationsAndDeprecatedQuays(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->configure($device, 'S2', label: 'Masquée', active: false);
        $this->configure($device, 'S3', label: 'Disparu', deprecated: true);

        self::assertSame(['Gare'], $this->titles($this->displayPage()->sampleForDevice($device)->screens));
    }

    public function testSamplePreviewOfADisabledDeviceStillShowsItsRotation(): void
    {
        $device = $this->device()->setActive(false);
        $this->configure($device, 'S1', label: 'Gare');

        self::assertSame(['Gare'], $this->titles($this->displayPage()->sampleForDevice($device)->screens));
    }

    public function testSamplePreviewOfAConfigurationShowsItEvenHiddenOrOnADeprecatedQuay(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $hidden = $this->configure($device, 'S2', label: 'Masquée', active: false);
        $deprecated = $this->configure($device, 'S3', label: 'Disparu', deprecated: true);

        self::assertSame(['Masquée'], $this->titles($this->displayPage()->sampleForConfiguration($hidden)->screens));
        $state = $this->displayPage()->sampleForConfiguration($deprecated);
        self::assertSame(['Disparu'], $this->titles($state->screens));
        self::assertSame(self::SAMPLE_ROUTES, $this->routes($state->screens[0]));
    }

    public function testUnavailableFeedGivesTheUnavailableScreenToTheTemplates(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');

        self::assertSame(
            ['screens' => [], 'state_message' => null, 'offline' => true],
            $this->displayPage()->forDevice($device)->templateVariables(),
        );
    }

    public function testEachQuayGivesOneScreenOfItsFirstPassages(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Direction travail');
        $this->configure($device, 'S2', label: 'Gare');
        $this->configure($device, 'S3', label: 'Centre');
        $this->index([
            'S1' => $this->passages(7),
            'S2' => $this->passages(5),
            'S3' => $this->passages(1),
        ]);

        $screens = $this->displayPage()->forDevice($device)->screens;

        self::assertSame(['Direction travail', 'Gare', 'Centre'], $this->titles($screens));
        self::assertSame(['1', '2', '3', '4', '5'], $this->routes($screens[0]));
        self::assertSame(['1', '2', '3', '4', '5'], $this->routes($screens[1]));
        self::assertSame(['1'], $this->routes($screens[2]));
    }

    public function testScreenNeverHoldsMorePassagesThanItsGridHasRows(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index(['S1' => $this->passages(7)]);

        $screens = $this->displayPage(passagesReadPerQuay: 10)->forDevice($device)->screens;

        self::assertSame(['Gare'], $this->titles($screens));
        self::assertSame(['1', '2', '3', '4', '5'], $this->routes($screens[0]));
    }

    public function testDeviceWithoutActiveConfigurationGivesNoScreen(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Masquée', active: false);
        $this->index(['S1' => [$this->passage('1', 3)]]);

        self::assertSame([], $this->displayPage()->forDevice($device)->screens);
    }

    public function testPassageOverdueWithinTheGraceIsShownAtZeroMinutes(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        // Annoncé il y a 3 min 30 s : en retard, mais encore attendu.
        $this->index(['S1' => [$this->passage('1', -4), $this->passage('2', 3)]]);

        $passages = $this->displayPage()->forDevice($device)->screens[0]->passages;

        self::assertSame(['1', '2'], array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $passages));
        self::assertSame([0, 3], array_map(static fn (ScreenPassage $passage): int => $passage->minutes, $passages));
    }

    public function testPassageOverdueBeyondTheGraceIsNotShown(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        // Annoncé il y a 5 min 30 s, plus que le délai de grâce de 5 min.
        $this->index(['S1' => [$this->passage('1', -6), $this->passage('2', 3)]]);

        self::assertSame(['2'], $this->routes($this->displayPage()->forDevice($device)->screens[0]));
    }

    public function testDeprecatedQuayWhosePassagesAreAllGoneLeavesTheRotation(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Disparu', deprecated: true);
        $this->index(['S1' => [$this->passage('1', -6)]]);

        self::assertSame([], $this->displayPage()->forDevice($device)->screens);
    }

    public function testMinutesAreRoundedDown(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index(['S1' => [
            new NextPassage('1', 'FF5500', 'FFFFFF', 'GARE', $this->clock->now()->modify('+59 seconds')),
            new NextPassage('2', 'FF5500', 'FFFFFF', 'GARE', $this->clock->now()->modify('+3 minutes 55 seconds')),
        ]]);

        $passages = $this->displayPage()->forDevice($device)->screens[0]->passages;

        self::assertSame([0, 3], array_map(static fn (ScreenPassage $passage): int => $passage->minutes, $passages));
    }

    public function testInvalidColorsAreLeftToTheScreenDefaults(): void
    {
        $device = $this->device();
        $this->configure($device, 'S1', label: 'Gare');
        $this->index(['S1' => [
            new NextPassage('1', 'a020f0', '', null, $this->clock->now()->modify('+5 minutes')),
            new NextPassage('2', 'red', '#FFFFFF', 'GARE', $this->clock->now()->modify('+6 minutes')),
        ]]);

        [$first, $second] = $this->displayPage()->forDevice($device)->screens[0]->passages;

        self::assertSame(['A020F0', null, ''], [$first->routeColor, $first->routeTextColor, $first->headsign]);
        self::assertSame([null, null], [$second->routeColor, $second->routeTextColor]);
    }

    private function assertUnavailable(DisplayPageState $state): void
    {
        self::assertSame([], $state->screens);
        self::assertTrue($state->feedUnavailable);
    }

    /**
     * @param int $passagesReadPerQuay limite du lecteur de l'index, celle d'un écran par défaut
     */
    private function displayPage(int $passagesReadPerQuay = DisplayPage::PASSAGES_PER_SCREEN): DisplayPage
    {
        $feed = new TripUpdatesFeed(new MockHttpClient(), $this->clock, 'https://gtfs-rt.test/trip-updates', self::MAX_AGE);
        $finder = new NextPassageFinder($this->store, $feed, $this->clock, $passagesReadPerQuay);

        return new DisplayPage($finder, new SamplePassages($this->clock), $this->clock);
    }

    private function device(): Device
    {
        return new Device(new User('lea@example.com'), 'Entrée');
    }

    private function configure(Device $device, string $stopId, string $label, bool $active = true, bool $deprecated = false): DisplayConfiguration
    {
        $quay = new Quay($stopId, 'QUAI '.$stopId, 43.6, 3.9, 'P'.$stopId);

        if ($deprecated) {
            $quay->markDeprecated();
        }

        $configuration = new DisplayConfiguration($device, $quay)->setLabel($label)->setActive($active);
        $device->appendDisplayConfiguration($configuration);

        return $configuration;
    }

    /**
     * Enregistre l'index d'un flux publié il y a $ageSeconds secondes.
     *
     * @param array<string, list<NextPassage>> $passagesByStopId
     */
    private function index(array $passagesByStopId, int $ageSeconds = 0): void
    {
        $publishedAt = $this->clock->now()->getTimestamp() - $ageSeconds;
        $this->store->save(new PassageIndex($publishedAt, $publishedAt, $passagesByStopId));
    }

    /**
     * @return list<NextPassage> lignes « 1 » à « $count », une minute d'écart
     */
    private function passages(int $count): array
    {
        return array_map(fn (int $i): NextPassage => $this->passage((string) $i, $i), range(1, $count));
    }

    /**
     * Passage de la ligne $route dans $minutes minutes (et une demi-minute,
     * pour que l'arrondi à la minute inférieure tombe sur $minutes).
     */
    private function passage(string $route, int $minutes): NextPassage
    {
        return new NextPassage($route, 'E2001A', 'FFFFFF', 'GARE CENTRALE', $this->clock->now()->modify(\sprintf('+%d seconds', $minutes * 60 + 30)));
    }

    /**
     * @param list<Screen> $screens
     *
     * @return list<string>
     */
    private function titles(array $screens): array
    {
        return array_map(static fn (Screen $screen): string => $screen->title, $screens);
    }

    /**
     * @return list<string>
     */
    private function routes(Screen $screen): array
    {
        return array_map(static fn (ScreenPassage $passage): string => $passage->routeShortName, $screen->passages);
    }
}
