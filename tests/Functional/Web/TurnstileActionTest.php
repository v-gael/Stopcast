<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Security\TurnstileVerifier;
use App\Tests\Support\WebTestTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * L'action que le serveur attend est celle que le gabarit donne au widget.
 * La clé secrète de test de .env saute ce contrôle : le vérificateur est
 * remplacé par un autre, sur une clé réelle, dont siteverify valide le jeton
 * pour l'action rendue dans la page. Un formulaire dont les deux actions
 * divergent refuse alors l'envoi.
 */
#[ResetDatabase]
final class TurnstileActionTest extends WebTestCase
{
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private KernelBrowser $client;

    private MockHttpClient $siteverify;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le vérificateur remplacé doit survivre d'une requête à l'autre.
        $this->client->disableReboot();
        UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testLoginExpectsTheActionOfItsWidget(): void
    {
        $this->validateTokensForTheActionRenderedOn('/', '#connexion');

        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/devices');
        self::assertSame(1, $this->siteverify->getRequestsCount());
    }

    public function testPasswordResetRequestExpectsTheActionOfItsWidget(): void
    {
        $this->validateTokensForTheActionRenderedOn('/reset-password', 'main form');

        $this->requestPasswordReset(self::EMAIL);

        self::assertResponseRedirects('/reset-password/check-email', 303);
        self::assertSame(1, $this->siteverify->getRequestsCount());
    }

    public function testAccessRequestExpectsTheActionOfItsWidget(): void
    {
        $this->validateTokensForTheActionRenderedOn('/demande-acces', 'main form');

        $this->requestAccess('paul@example.com');

        self::assertResponseRedirects('/demande-acces/envoyee');
        self::assertSame(1, $this->siteverify->getRequestsCount());
    }

    /**
     * Remplace le vérificateur par un vérificateur sur une clé réelle, dont
     * siteverify valide tout jeton pour l'hôte du client de test et l'action
     * du widget de la page.
     */
    private function validateTokensForTheActionRenderedOn(string $page, string $form): void
    {
        $crawler = $this->client->request('GET', $page);
        $action = $crawler->filter($form.' [data-turnstile-action-value]')->attr('data-turnstile-action-value');
        self::assertNotEmpty($action);

        $this->siteverify = new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                json_encode(['success' => true, 'hostname' => 'localhost', 'action' => $action], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            'https://challenges.cloudflare.com',
        );
        self::getContainer()->set(TurnstileVerifier::class, new TurnstileVerifier($this->siteverify, new NullLogger(), 'real-secret-key'));
    }
}
