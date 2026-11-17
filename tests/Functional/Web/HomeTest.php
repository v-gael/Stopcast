<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Support\TurnstileSiteverifyMock;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomeTest extends WebTestCase
{
    public function testShowcaseIsPublic(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Le panneau de votre arrêt, sur un écran chez vous');
        // Aucune session ouverte pour un simple visiteur.
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    /**
     * Haut de page : trois boutons sur une ligne, puis le réseau desservi.
     */
    public function testHeroLeadsToTheAccessRequestTheCodeAndHowItWorks(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('main section')->first();
        self::assertSame(
            [['Demander un accès', '/demande-acces'], ['Voir le code sur GitHub', 'https://github.com/v-gael/Stopcast'], ['Comment ça marche ↓', '#comment-ca-marche']],
            $hero->filter('a')->each(static fn (Crawler $link): array => [trim($link->text()), (string) $link->attr('href')]),
        );
        self::assertCount(1, $crawler->filter('section#comment-ca-marche'));
        self::assertStringContainsString('Toutes les lignes du réseau TCAT, à Troyes', $hero->text());
    }

    /**
     * Les écrans de la page sont la vraie page d'affichage, sur le jeu de
     * démo : plus d'image animée.
     */
    public function testScreensAreTheDisplayPageOnTheDemoDataset(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertCount(0, $crawler->filter('main img'));
        $sources = array_unique($crawler->filter('main iframe')->each(static fn (Crawler $frame): string => (string) $frame->attr('src')));
        sort($sources);
        self::assertSame(['/vitrine/ecran', '/vitrine/ecran?mode=fixe', '/vitrine/ecran?mode=maison', '/vitrine/ecran?mode=pilote'], $sources);
    }

    public function testShowcaseSellsTheService(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        $headings = $crawler->filter('main h2')->each(static fn (Crawler $title): string => trim($title->text()));
        self::assertSame(['Du vrai temps réel', 'Plusieurs arrêts sur un écran', "L'écran que vous avez déjà", 'Comment ça marche', 'En bref'], $headings);
        self::assertSame(
            ['Créez un dispositif', 'Ajoutez vos arrêts', "Ouvrez Stopcast sur l'écran", 'Saisissez le code'],
            $crawler->filter('#comment-ca-marche [data-showcase-walkthrough-target="step"] [data-step-title]')->each(static fn (Crawler $title): string => trim($title->text())),
        );
        self::assertCount(1, $crawler->filter('#en-bref a[href="/demande-acces"]'));
    }

    public function testFooterStatesTheServiceIsIndependent(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertStringContainsString('Service indépendant, sans lien avec la TCAT ni avec Troyes Champagne Métropole.', $crawler->filter('footer')->text());
    }

    /**
     * « Se connecter », en haut à droite, ouvre le panneau de connexion : un
     * popover natif qui porte le formulaire. Le lien GitHub est passé dans le
     * pied de page, et la carte du bas de page a disparu.
     */
    public function testHeaderOpensTheLoginPanel(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        $toggle = $crawler->filter('header button[popovertarget="connexion"]');
        self::assertSame('Se connecter', trim($toggle->text()));
        self::assertCount(1, $crawler->filter('header #connexion[popover] form[action="/login"] input[name="_password"]'));
        self::assertCount(0, $crawler->filter('main form[action="/login"]'));
        self::assertCount(0, $crawler->filter('header a[href="https://github.com/v-gael/Stopcast"]'));
        self::assertCount(1, $crawler->filter('footer a[href="https://github.com/v-gael/Stopcast"]'));
        self::assertSame('false', $crawler->filter('#connexion')->attr('data-login-panel-open-value'));
    }

    public function testLoginPanelOffersAForgottenPasswordLink(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/reset-password', $crawler->filter('#connexion')->selectLink('Mot de passe oublié ?')->attr('href'));
    }

    /**
     * Clé de site de test de .env : le widget s'affiche sans compte Cloudflare.
     * Il n'est créé qu'à l'ouverture du panneau (data-login-panel-target).
     */
    public function testLoginPanelDefersTheTurnstileWidget(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('#connexion form[action="/login"] [data-login-panel-target="captcha"][data-turnstile-site-key-value="'.TurnstileSiteverifyMock::SITE_KEY.'"]'));
        self::assertCount(0, $crawler->filter('#connexion [data-controller~="turnstile"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesWithTheLoginPanel(): iterable
    {
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'sources des données' => ['/sources'];
    }

    #[DataProvider('pagesWithTheLoginPanel')]
    public function testPublicPagesOfferTheLoginPanel(string $path): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('header #connexion form[action="/login"]'));
        // Aucune session ouverte pour un simple visiteur.
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    /**
     * Le parcours de mot de passe a son propre formulaire : pas de second en en-tête.
     */
    public function testPasswordResetPageHasNoLoginPanel(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('header #connexion'));
        self::assertCount(0, $crawler->filter('header button[popovertarget]'));
    }
}
