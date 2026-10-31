<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LegalNoticeTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testLegalNoticeIsPublic(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');
        self::assertSelectorTextContains('main', 'v-gael, particulier non professionnel');
        self::assertSelectorTextContains('main', 'contact@gaelv.com');
        self::assertSelectorTextContains('main', 'Hetzner Online GmbH');
        self::assertSelectorTextContains('main', 'Cloudflare, Inc.');
        // Aucune session ouverte pour un simple visiteur.
        self::assertResponseNotHasCookie('PHPSESSID');
    }

    public function testPublicFooterLinksToTheLegalNotice(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/mentions-legales', $this->footerNav($crawler)->selectLink('Mentions légales')->attr('href'));
        self::assertSelectorTextContains('footer', 'Stopcast, projet open source sous licence MIT.');
    }

    public function testSpaceFooterLinksToTheLegalNotice(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $crawler = $client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertSame('/mentions-legales', $this->footerNav($crawler)->selectLink('Mentions légales')->attr('href'));
    }

    public function testFooterMarksTheCurrentPage(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/mentions-legales');

        self::assertSame('page', $this->footerNav($crawler)->selectLink('Mentions légales')->attr('aria-current'));
    }

    private function footerNav(Crawler $crawler): Crawler
    {
        $nav = $crawler->filter('footer nav[aria-label="Informations légales"]');
        self::assertCount(1, $nav);

        return $nav;
    }
}
