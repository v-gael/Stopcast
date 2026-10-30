<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * IP du client derrière un proxy (TRUSTED_PROXIES), vue par la limite d'essais
 * à la connexion : 5 échecs par identifiant + IP.
 */
final class TrustedProxiesTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string WRONG_PASSWORD = 'mauvais-mot-de-passe';

    // Adresse du client de test (REMOTE_ADDR), ici le proxy.
    private const string PROXY = '127.0.0.1';

    private KernelBrowser $client;

    private string $trustedProxies;

    protected function setUp(): void
    {
        $trustedProxies = $_ENV['TRUSTED_PROXIES'];
        self::assertIsString($trustedProxies);
        $this->trustedProxies = $trustedProxies;
    }

    protected function tearDown(): void
    {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $this->trustedProxies;
        // La configuration des proxys est statique : le noyau ne la remet pas
        // à zéro quand TRUSTED_PROXIES est vide.
        Request::setTrustedProxies([], 0);

        parent::tearDown();
    }

    public function testBehindATrustedProxyEachClientHasItsOwnCounter(): void
    {
        $this->createClientWithTrustedProxies(self::PROXY);

        $this->failFiveLoginsFrom('203.0.113.1');
        $this->client->setServerParameter('HTTP_X_FORWARDED_FOR', '203.0.113.2');
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    public function testBehindATrustedProxyTheBlockedClientStaysBlocked(): void
    {
        $this->createClientWithTrustedProxies(self::PROXY);

        $this->failFiveLoginsFrom('203.0.113.1');
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testWithoutTrustedProxyTheForwardedForHeaderIsIgnored(): void
    {
        $this->createClientWithTrustedProxies('');

        $this->failFiveLoginsFrom('203.0.113.1');
        $this->client->setServerParameter('HTTP_X_FORWARDED_FOR', '203.0.113.2');
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    /**
     * Le noyau lit TRUSTED_PROXIES à son démarrage, donc avant le client.
     */
    private function createClientWithTrustedProxies(string $trustedProxies): void
    {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $trustedProxies;

        $this->client = self::createClient();
        // Le compteur vit en mémoire dans le conteneur : il doit survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        UserFactory::createOne(['email' => self::EMAIL]);
    }

    private function failFiveLoginsFrom(string $clientIp): void
    {
        $this->client->setServerParameter('HTTP_X_FORWARDED_FOR', $clientIp);

        for ($i = 0; $i < 5; ++$i) {
            $this->logIn(self::EMAIL, self::WRONG_PASSWORD);
            self::assertResponseRedirects('/');
        }
    }
}
