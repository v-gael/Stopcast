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
        self::assertSelectorTextContains('h1', 'Le panneau de votre arrêt de bus');
        // Aucune session ouverte pour un simple visiteur.
        self::assertSame([], $client->getResponse()->headers->getCookies());

        $images = $crawler->filter('main img')->each(static fn (Crawler $image): string => (string) $image->attr('src'));
        self::assertCount(2, $images);
        self::assertMatchesRegularExpression('#^/assets/images/demo-[\w-]+\.gif$#', $images[0]);
        self::assertMatchesRegularExpression('#^/assets/images/afficheur-en-situation-[\w-]+\.jpg$#', $images[1]);

        self::assertCount(1, $crawler->filter('main a[href="https://github.com/v-gael/Stopcast"]'));
    }

    public function testShowcaseExplainsHowItWorks(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        $steps = $crawler->filter('main ol > li h3')->each(static fn (Crawler $title): string => trim($title->text()));
        self::assertSame(['1. Référentiel statique', '2. Flux temps réel', '3. Afficheur'], $steps);
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
