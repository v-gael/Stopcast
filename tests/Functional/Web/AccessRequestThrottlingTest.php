<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\TurnstileSiteverifyMock;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Limite des demandes d'accès : 5 demandes par heure et par adresse IP, que
 * l'adresse saisie ait un compte ou non.
 */
#[ResetDatabase]
final class AccessRequestThrottlingTest extends WebTestCase
{
    use WebTestTrait;

    private const string TOO_MANY_REQUESTS = 'Trop de demandes. Réessayez plus tard.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le compteur vit en mémoire dans le conteneur : il doit survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        UserFactory::createOne(['email' => 'admin@stopcast.test', 'role' => Role::Admin]);
        UserFactory::createOne(['email' => 'lea@example.com']);
    }

    public function testSixthRequestFromTheSameIpIsRefused(): void
    {
        $this->requestAccess('lea@example.com');
        self::assertResponseRedirects('/access-request/sent');
        $this->exhaustTheLimit(4);

        $this->requestAccess('paul@example.com');

        $this->assertRefused();
    }

    /**
     * Le captcha est validé avec le formulaire, avant la limite : un robot
     * sans jeton valide ne bloque pas les demandes de son IP.
     */
    public function testFailedTurnstileChecksDoNotCountTowardsTheLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->requestAccess('paul@example.com', turnstileToken: TurnstileSiteverifyMock::INVALID_TOKEN);
            self::assertResponseStatusCodeSame(422);
        }

        $this->requestAccess('paul@example.com');

        self::assertResponseRedirects('/access-request/sent');
        self::assertQueuedEmailCount(1);
    }

    public function testAnotherIpIsNotConcerned(): void
    {
        $this->exhaustTheLimit(5);

        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->requestAccess('paul@example.com');

        self::assertResponseRedirects('/access-request/sent');
        self::assertQueuedEmailCount(1);
    }

    private function exhaustTheLimit(int $requests): void
    {
        for ($i = 1; $i <= $requests; ++$i) {
            $this->requestAccess(\sprintf('inconnu%d@example.com', $i));
            self::assertResponseRedirects('/access-request/sent');
        }
    }

    private function assertRefused(): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
        self::assertSelectorTextContains('main form [role="alert"]', self::TOO_MANY_REQUESTS);
    }
}
