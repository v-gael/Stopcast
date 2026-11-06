<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LegalTermsTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    public function testTermsArePublic(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/conditions-utilisation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Conditions d'utilisation");
        self::assertSelectorTextContains('main', "sur invitation d'un administrateur");
        self::assertSelectorTextContains('main', "sans garantie de disponibilité ni d'exactitude");
        self::assertSelectorTextContains('main', 'droit français');
        self::assertSelectorTextContains('main', 'contact@gaelv.com');
        self::assertSame('page', $this->footerNav($crawler)->selectLink("Conditions d'utilisation")->attr('aria-current'));
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testPublicFooterLinksToTheTerms(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/conditions-utilisation', $this->footerNav($crawler)->selectLink("Conditions d'utilisation")->attr('href'));
    }

    public function testSpaceFooterLinksToTheTerms(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $crawler = $client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertSame('/conditions-utilisation', $this->footerNav($crawler)->selectLink("Conditions d'utilisation")->attr('href'));
    }
}
