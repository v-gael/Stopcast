<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Support\MockClockTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Écran de la vitrine : la vraie page d'affichage sur le jeu de démo, figée à
 * 8 h 50, que la page d'accueil montre dans ses cadres.
 */
final class ShowcaseScreenTest extends WebTestCase
{
    use MockClockTrait;

    public function testShowsTheDemoRotationFrozenAtTenToNine(): void
    {
        $client = self::createClient();
        $this->mockClock('2026-11-17 22:14:00');

        $crawler = $client->request('GET', '/vitrine/ecran');

        self::assertResponseIsSuccessful();
        self::assertSame([], $client->getResponse()->headers->getCookies());
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));

        $screens = $crawler->filter('[data-display-target="screen"]');
        self::assertSame(['BEURNONVILLE', 'Direction travail', '1ER RAM'], $screens->filter('.screen__stop')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['08:50', '08:50', '08:50'], $screens->filter('.screen__clock')->each(static fn (Crawler $clock): string => $clock->text()));

        self::assertSame([
            ['2', '#E20019', '2', 'BUCHERES PARC LOGISTIQUE'],
            ['4', '#F29400', '4', 'HAMELET-DULOU'],
            ['N', '#CDDE8D', '6', 'COEUR DE TROYES'],
            ['1', '#009DE0', '9', 'CENTRE ADMINISTRATIF'],
            ['N', '#CDDE8D', '14', 'COEUR DE TROYES'],
        ], $this->passages($screens->eq(0)));
        self::assertSame([
            ['N', '#CDDE8D', '2', 'COEUR DE TROYES'],
            ['4', '#F29400', '8', 'HAMELET-DULOU'],
            ['N', '#CDDE8D', '10', 'COEUR DE TROYES'],
            ['5', '#00509D', '12', 'FOUCHY'],
            ['1', '#009DE0', '13', 'CENTRE ADMINISTRATIF'],
        ], $this->passages($screens->eq(1)));
        self::assertCount(0, $screens->eq(2)->filter('[data-display-target="passage"]'));
        self::assertNull($screens->eq(2)->filter('[data-display-target="noPassage"]')->attr('hidden'));

        // Le contrôleur de l'écran garde l'heure figée et ne relit rien.
        $display = $crawler->filter('[data-controller~="display"]');
        self::assertSame((string) new \DateTimeImmutable('2026-11-17 08:50:00')->getTimestamp(), $display->attr('data-display-now-value'));
        self::assertNull($display->attr('data-display-url-value'));
        self::assertSame('true', $display->attr('data-display-rotation-value'));
    }

    public function testCanBeFramedByTheSiteOnly(): void
    {
        $client = self::createClient();

        $client->request('GET', '/vitrine/ecran');

        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    /**
     * « fixe » montre le premier écran sans tourner ; « pilote » attend les
     * commandes de la page d'accueil, écran allumé et vide, avec l'écran
     * d'appairage prêt.
     */
    public function testModesSetTheStartingState(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/vitrine/ecran?mode=fixe');
        self::assertSame('false', $crawler->filter('[data-controller~="display"]')->attr('data-display-rotation-value'));

        $crawler = $client->request('GET', '/vitrine/ecran?mode=pilote');
        self::assertSame('false', $crawler->filter('[data-controller~="display"]')->attr('data-display-rotation-value'));
        self::assertNull($crawler->filter('[data-panel="idle"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-panel="display"]')->attr('hidden'));
        self::assertSame('K7F-3QX', $crawler->filter('[data-panel="pairing"] .pairing__code')->text());
        self::assertCount(1, $crawler->filter('[data-panel="pairing"] .pairing__qr svg'));
    }

    /**
     * Le dispositif « Maison » du parcours n'a que les deux quais que le
     * visiteur y ajoute : l'écran piloté, une fois appairé, et sa carte
     * « maison » dans la liste des dispositifs ne tournent que sur eux.
     */
    public function testWalkthroughDeviceShowsOnlyTheTwoQuaysAddedToIt(): void
    {
        $client = self::createClient();

        foreach (['pilote' => 'false', 'maison' => 'true'] as $mode => $rotation) {
            $crawler = $client->request('GET', '/vitrine/ecran?mode='.$mode);

            $display = $crawler->filter('[data-panel="display"]');
            self::assertSame(['BEURNONVILLE', 'Direction travail'], $display->filter('.screen__stop')->each(static fn (Crawler $title): string => $title->text()), $mode);
            self::assertSame($rotation, $display->attr('data-display-rotation-value'), $mode);
        }

        self::assertNull($crawler->filter('[data-panel="display"]')->attr('hidden'));
    }

    public function testUnknownModeIsNotFound(): void
    {
        $client = self::createClient();

        $client->request('GET', '/vitrine/ecran?mode=autre');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<array{string, string, string, string}> ligne, couleur, délai, destination
     */
    private function passages(Crawler $screen): array
    {
        return $screen->filter('[data-display-target="passage"]')->each(static fn (Crawler $passage): array => [
            $passage->filter('.passage__route')->text(),
            (string) $passage->filter('.passage__route')->attr('data-route-color'),
            $passage->filter('[data-display-target="minutes"]')->text(),
            $passage->filter('.passage__headsign')->text(),
        ]);
    }
}
