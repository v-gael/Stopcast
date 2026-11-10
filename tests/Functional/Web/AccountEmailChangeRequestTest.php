<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Demande de changement d'email depuis « Mon compte ».
 */
final class AccountEmailChangeRequestTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NEW_EMAIL = 'lea.nouvelle@example.com';

    private const string TOO_MANY_REQUESTS = 'Trop de demandes. Réessayez plus tard.';

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Les compteurs vivent en mémoire dans le conteneur.
        $this->client->disableReboot();

        $this->user = UserFactory::createOne(['email' => self::EMAIL]);
        $this->client->loginUser($this->user);
    }

    public function testAccountPageLinksToTheForm(): void
    {
        $crawler = $this->client->request('GET', '/account');

        self::assertSame('/account/email', $crawler->selectLink('Modifier mon adresse')->attr('href'));
    }

    public function testValidRequestSendsTheLinkToTheNewAddressAndKeepsTheEmail(): void
    {
        $this->requestEmailChange(' Lea.Nouvelle@Example.com ');

        self::assertResponseRedirects('/account', 303);
        self::assertQueuedEmailCount(1);
        $email = $this->lastEmail();
        self::assertSame(self::NEW_EMAIL, $email->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#/email-change/[\w-]+\?_expiration=\d+&_hash=#', (string) $email->getTextBody());
        self::assertStringContainsString('ignorez', (string) $email->getTextBody());
        self::assertStringNotContainsString(self::EMAIL, (string) $email->getTextBody());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', \sprintf('Un lien de confirmation a été envoyé à %s, valable 1 heure.', self::NEW_EMAIL));
        self::assertSame(self::EMAIL, $this->storedUser(self::EMAIL)->getEmail());
    }

    public function testWrongPasswordIsRefusedWithoutConsumingARequest(): void
    {
        $this->requestEmailChange(self::NEW_EMAIL, 'mauvais-mot-de-passe');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe actuel est incorrect.');
        self::assertQueuedEmailCount(0);

        for ($i = 1; $i <= 3; ++$i) {
            $this->requestEmailChange(\sprintf('nouvelle%d@example.com', $i));
            self::assertResponseRedirects('/account', 303);
        }
    }

    public function testPasswordAttemptsAreLimited(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->requestEmailChange(self::NEW_EMAIL, 'mauvais-mot-de-passe');
        }

        $this->requestEmailChange(self::NEW_EMAIL);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', "Trop d'essais. Réessayez plus tard.");
        self::assertQueuedEmailCount(0);
    }

    public function testCurrentAddressIsRefusedAfterNormalization(): void
    {
        $this->requestEmailChange(' LEA@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', "C'est déjà l'adresse de votre compte.");
        self::assertQueuedEmailCount(0);
    }

    public function testAddressOfAnotherAccountIsRefusedAfterNormalization(): void
    {
        UserFactory::createOne(['email' => 'paul@example.com']);

        $this->requestEmailChange('PAUL@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà avec cet email.');
        self::assertQueuedEmailCount(0);
    }

    public function testFourthRequestWithinTheHourIsRefused(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->requestEmailChange(\sprintf('nouvelle%d@example.com', $i));
            self::assertResponseRedirects('/account', 303);
        }

        $this->requestEmailChange(self::NEW_EMAIL);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', self::TOO_MANY_REQUESTS);
        self::assertQueuedEmailCount(0);
    }

    public function testRefusedAddressesConsumeRequests(): void
    {
        UserFactory::createOne(['email' => 'paul@example.com']);

        for ($i = 1; $i <= 3; ++$i) {
            $this->requestEmailChange('paul@example.com');
            self::assertSelectorTextContains('body', 'Un compte existe déjà avec cet email.');
        }

        $this->requestEmailChange('paul@example.com');

        self::assertSelectorTextContains('body', self::TOO_MANY_REQUESTS);
        self::assertSelectorTextNotContains('body', 'Un compte existe déjà avec cet email.');
    }

    public function testFormRequiresAnAccount(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/account/email');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
