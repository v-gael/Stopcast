<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LegalPrivacyTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    public function testPrivacyPolicyIsPublic(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/confidentialite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Politique de confidentialité');
        self::assertSelectorTextContains('main', 'v-gael');
        self::assertSelectorTextContains('main', 'contact@gaelv.com');
        self::assertSelectorTextContains('main', 'CNIL');
        self::assertSame('page', $this->footerNav($crawler)->selectLink('Confidentialité')->attr('aria-current'));
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testPrivacyPolicyStatesTheRetentionPeriods(): void
    {
        $client = self::createClient();

        $client->request('GET', '/confidentialite');

        self::assertSelectorTextContains('main', 'Compte sans connexion pendant 2 ans');
        self::assertSelectorTextContains('main', 'avertissement par email 30 jours avant');
        self::assertSelectorTextContains('main', 'supprimés à leur expiration');
        self::assertSelectorTextContains('main', "30 jours après l'expiration de son dernier lien");
        self::assertSelectorTextContains('main', 'Journaux du serveur : environ 14 jours');
        self::assertSelectorTextContains('main', 'Sauvegardes et liste des comptes supprimés : 30 jours');
    }

    public function testPrivacyPolicyListsTheProcessors(): void
    {
        $client = self::createClient();

        $client->request('GET', '/confidentialite');

        foreach (['OVH', 'Cloudflare', 'Brevo', 'Sentry', 'UptimeRobot', 'Healthchecks.io', 'Have I Been Pwned'] as $service) {
            self::assertSelectorTextContains('main', $service);
        }
    }

    public function testTermsLinkToThePrivacyPolicy(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/conditions-utilisation');

        self::assertSame('/confidentialite', $crawler->filter('main')->selectLink('politique de confidentialité')->attr('href'));
    }

    public function testPublicFooterLinksToThePrivacyPolicy(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/confidentialite', $this->footerNav($crawler)->selectLink('Confidentialité')->attr('href'));
    }

    public function testSpaceFooterLinksToThePrivacyPolicy(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $crawler = $client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertSame('/confidentialite', $this->footerNav($crawler)->selectLink('Confidentialité')->attr('href'));
    }
}
