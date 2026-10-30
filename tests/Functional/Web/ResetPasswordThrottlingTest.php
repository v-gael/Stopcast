<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Limite des demandes de « mot de passe oublié » : 5 demandes par adresse IP,
 * que le compte existe ou non.
 */
final class ResetPasswordThrottlingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    private const string OTHER_EMAIL = 'paul@example.com';

    private const string TOO_MANY_REQUESTS = 'Trop de demandes. Réessayez plus tard.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le compteur vit en mémoire dans le conteneur : il doit survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        UserFactory::createOne(['email' => self::EMAIL]);
        UserFactory::createOne(['email' => self::OTHER_EMAIL]);
    }

    public function testSixthRequestFromTheSameIpIsRefused(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->requestReset(\sprintf('inconnu%d@example.com', $i));
            $this->assertConfirmationPage();
        }

        $this->requestReset(self::EMAIL);
        self::assertQueuedEmailCount(1);
        $this->assertConfirmationPage();

        $this->requestReset(self::OTHER_EMAIL);

        $this->assertRefused();
    }

    public function testRefusalIsTheSameForAnUnknownAndAnExistingAccount(): void
    {
        $this->exhaustTheLimit();

        $this->requestReset('inconnu@example.com');
        $this->assertRefused();
        $unknownAccountPage = $this->mainContent();

        $this->requestReset(self::EMAIL);
        $this->assertRefused();
        self::assertSame($unknownAccountPage, $this->mainContent());
    }

    public function testAnotherIpIsNotConcerned(): void
    {
        $this->exhaustTheLimit();

        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->requestReset(self::EMAIL);

        self::assertQueuedEmailCount(1);
        $this->assertConfirmationPage();
    }

    /**
     * Cinq demandes acceptées depuis l'adresse par défaut du client.
     */
    private function exhaustTheLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->requestReset(\sprintf('inconnu%d@example.com', $i));
            $this->assertConfirmationPage();
        }
    }

    private function assertRefused(): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
        self::assertSelectorTextContains('main form [role="alert"]', self::TOO_MANY_REQUESTS);
    }

    private function assertConfirmationPage(): void
    {
        self::assertResponseRedirects('/reset-password/check-email');
    }

    private function mainContent(): string
    {
        return $this->client->getCrawler()->filter('main')->text();
    }

    private function requestReset(string $email): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Envoyer le lien')->form([
            'reset_password_request[email]' => $email,
        ]));
    }
}
