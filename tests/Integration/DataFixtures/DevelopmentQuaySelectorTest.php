<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\DevelopmentQuaySelection;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DevelopmentQuaySelectorTest extends KernelTestCase
{
    use DevelopmentQuaySelectorFactoryTrait;
    use ResetDatabase;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $r1 = new Route('R1', '1', 'FF5500', 'FFFFFF');
        $r2 = new Route('R2', '2', '0055FF', 'FFFFFF');
        $r3 = new Route('R3', '3', '00AA00', 'FFFFFF');

        // Lignes par quai, pour le repli : S5 (3), S4 (2), S6 (2), les autres (1 ou 0).
        $servedBy = ['S1' => [$r1], 'S2' => [$r1], 'S3' => [$r1], 'S4' => [$r1, $r2], 'S5' => [$r1, $r2, $r3], 'S6' => [$r2, $r3], 'S7' => []];

        foreach ($servedBy as $stopId => $routes) {
            $quay = new Quay($stopId, 'QUAI '.$stopId, 38.5, -28.6, 'P'.$stopId);
            foreach ($routes as $route) {
                $quay->addRoute($route);
            }

            $entityManager->persist($quay);
        }

        $inactive = new Quay('S0', 'QUAI INACTIF', 38.5, -28.6, 'P0');
        $inactive->markDeprecated();

        $entityManager->persist($inactive);

        foreach ([$r1, $r2, $r3] as $route) {
            $entityManager->persist($route);
        }

        // T9 se termine à S3 : son passage n'y est pas affiché, donc pas compté.
        $entityManager->persist(new Trip('T9', $r1, 'QUAI S3', 'S3'));
        $entityManager->flush();
    }

    public function testQuaysWithTheMostPassagesComeFirst(): void
    {
        $selection = $this->selectorWithFeed([
            'T1' => ['S1', 'S2', 'S3'],
            'T2' => ['S2', 'S3'],
            'T3' => ['S3', 'S4'],
            'T4' => ['S2'],
        ])->select(3);

        self::assertSame(['S2', 'S3', 'S1'], $this->stopIdsOf($selection));
        self::assertNull($selection->fallbackReason);
    }

    public function testTieIsBrokenByStopId(): void
    {
        $selection = $this->selectorWithFeed([
            'T1' => ['S4', 'S3', 'S2', 'S1'],
        ])->select(3);

        self::assertSame(['S1', 'S2', 'S3'], $this->stopIdsOf($selection));
    }

    public function testPassagesHiddenByTheApiAreNotCounted(): void
    {
        $selection = $this->selectorWithFeed([
            'T9' => ['S3'],
            'T10' => ['S3'],
            'T1' => ['S1', 'S2', 'S3'],
            'T2' => ['S1', 'S2', 'S4'],
            'T3' => ['S4'],
        ])->select(3);

        // S3 : 3 passages dans le flux, mais celui de T9 (terminus) n'est pas affiché.
        self::assertSame(['S1', 'S2', 'S3'], $this->stopIdsOf($selection));
    }

    public function testInactiveQuaysAreNeverSelected(): void
    {
        $selection = $this->selectorWithFeed([
            'T1' => ['S0', 'S1'],
            'T2' => ['S0', 'S2'],
            'T3' => ['S0', 'S3'],
        ])->select(3);

        self::assertSame(['S1', 'S2', 'S3'], $this->stopIdsOf($selection));
    }

    public function testFewerRealtimeQuaysAreCompletedWithTheMostServedOnes(): void
    {
        $selection = $this->selectorWithFeed([
            'T1' => ['S1', 'S5'],
            'T2' => ['S1'],
        ])->select(3);

        // S5, déjà retenu grâce au flux, n'est pas repris : S4 et S6 (2 lignes), départagés par stop_id.
        self::assertSame(['S1', 'S5', 'S4'], $this->stopIdsOf($selection));
        self::assertNotNull($selection->fallbackReason);
    }

    public function testEmptyFeedFallsBackToTheMostServedQuays(): void
    {
        $selection = $this->selectorWithFeed([])->select(3);

        self::assertSame(['S5', 'S4', 'S6'], $this->stopIdsOf($selection));
        self::assertNotNull($selection->fallbackReason);
    }

    public function testUnavailableFeedFallsBackToTheMostServedQuays(): void
    {
        $selection = $this->selectorWithUnavailableFeed()->select(3);

        self::assertSame(['S5', 'S4', 'S6'], $this->stopIdsOf($selection));
        self::assertStringContainsString('indisponible', (string) $selection->fallbackReason);
    }

    /**
     * @return list<string>
     */
    private function stopIdsOf(DevelopmentQuaySelection $selection): array
    {
        return array_map(static fn (Quay $quay): string => $quay->getGtfsStopId(), $selection->quays);
    }
}
