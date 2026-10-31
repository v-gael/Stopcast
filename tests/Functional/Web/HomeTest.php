<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

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
        self::assertResponseNotHasCookie('PHPSESSID');

        $images = $crawler->filter('main img')->each(static fn (Crawler $image): string => (string) $image->attr('src'));
        self::assertCount(2, $images);
        self::assertMatchesRegularExpression('#^/assets/images/demo-[\w-]+\.gif$#', $images[0]);
        self::assertMatchesRegularExpression('#^/assets/images/afficheur-en-situation-[\w-]+\.jpg$#', $images[1]);

        self::assertCount(1, $crawler->filter('main a[href="https://github.com/v-gael/Stopcast"]'));
        self::assertCount(1, $crawler->filter('main a[href="https://github.com/v-gael/StopcastDisplay"]'));
    }

    public function testShowcaseExplainsHowItWorks(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        $steps = $crawler->filter('main ol > li h3')->each(static fn (Crawler $title): string => trim($title->text()));
        self::assertSame(['1. Référentiel statique', '2. Flux temps réel', '3. Afficheur'], $steps);
    }

    public function testLoginFormOffersAForgottenPasswordLink(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/reset-password', $crawler->filter('#connexion')->selectLink('Mot de passe oublié ?')->attr('href'));
    }
}
