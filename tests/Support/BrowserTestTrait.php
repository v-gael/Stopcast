<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Gestes des tests navigateur (Panther) : visite d'une page et contrôle de la
 * console de Chrome. Le serveur web de Panther est un autre processus que le
 * test : ni loginUser() ni remplacement de service n'y ont d'effet.
 *
 * @phpstan-require-extends PantherTestCase
 */
trait BrowserTestTrait
{
    /**
     * Le navigateur de Panther, partagé entre les tests : sans les cookies
     * d'un test précédent.
     */
    private function anonymousClient(): Client
    {
        $client = self::createPantherClient();
        $client->getCookieJar()->clear();

        return $client;
    }

    /**
     * Visite une page puis vérifie que la console est restée propre.
     */
    private function visitWithCleanConsole(Client $client, string $path): Crawler
    {
        $crawler = $client->request('GET', $path);
        $this->assertNoBrowserErrors($client, $path);

        return $crawler;
    }

    /**
     * Échoue sur tout message de niveau erreur reçu depuis le dernier appel :
     * console.error, exception ou promesse rejetée sans catch, violation CSP,
     * requête en échec. Les avertissements sont ignorés.
     */
    private function assertNoBrowserErrors(Client $client, string $step): void
    {
        $errors = [];
        foreach ($client->getWebDriver()->manage()->getLog('browser') as $entry) {
            if (\is_array($entry) && 'SEVERE' === ($entry['level'] ?? null) && \is_string($entry['message'] ?? null)) {
                $errors[] = $entry['message'];
            }
        }

        self::assertSame([], $errors, \sprintf('Erreurs dans la console de Chrome sur « %s ».', $step));
    }

    /**
     * Connexion depuis le formulaire de la vitrine, une fois que le widget
     * Turnstile (clé de test, jeton factice) a rempli son champ.
     */
    private function logInFromShowcase(Client $client, string $email, string $password): void
    {
        $crawler = $this->visitWithCleanConsole($client, '/');
        $this->waitForTurnstileToken($client);

        $client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
        $client->waitFor('header nav');
        $this->assertNoBrowserErrors($client, 'connexion');
    }

    /**
     * Dernière lecture de la console, après chaque test : une erreur arrivée
     * après le dernier contrôle (requête, contrôleur Stimulus, iframe) n'est
     * pas perdue, ni reportée sur le test suivant.
     */
    #[After]
    protected function assertNoBrowserErrorsAtTheEnd(): void
    {
        if (self::$pantherClient instanceof Client) {
            $this->assertNoBrowserErrors(self::$pantherClient, 'fin du test');
        }
    }

    private function waitForTurnstileToken(Client $client): void
    {
        $client->wait(15)->until(static fn (RemoteWebDriver $driver): bool => '' !== $driver->executeScript(
            'return document.querySelector(\'input[name="cf-turnstile-response"]\')?.value ?? "";',
        ));
    }
}
