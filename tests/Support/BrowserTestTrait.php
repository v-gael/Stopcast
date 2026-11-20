<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Panther\ServerExtensionLegacy;

/**
 * Gestes des tests navigateur (Panther) : visite d'une page et contrôle de la
 * console de Chrome. Le serveur web de Panther est un autre processus que le
 * test : ni loginUser() ni remplacement de service n'y ont d'effet. Il ne
 * voit pas non plus une transaction de DAMA : les tests navigateur ont leur
 * configuration, sans DAMA (phpunit.browser.dist.xml).
 *
 * @phpstan-require-extends PantherTestCase
 */
trait BrowserTestTrait
{
    /**
     * Sous DAMA, les données du test resteraient dans sa transaction : la
     * page les chercherait en vain, loin de la cause.
     */
    #[Before]
    protected function assertRunningWithoutDama(): void
    {
        self::assertFalse(
            StaticDriver::isKeepStaticConnections(),
            'Test navigateur lancé sous DAMA, dont le serveur de Panther ne voit pas la transaction : le lancer par php bin/phpunit -c phpunit.browser.dist.xml (make test-browser).',
        );
    }

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

        self::assertSame([], $errors, \sprintf('Erreurs dans la console de Chrome sur « %s » (%s).', $step, $client->getCurrentURL()));
    }

    /**
     * Connexion depuis le panneau de l'en-tête de la vitrine, une fois que le widget
     * Turnstile (clé de test, jeton factice) a rempli son champ.
     */
    private function logInFromShowcase(Client $client, string $email, string $password): void
    {
        $crawler = $this->visitWithCleanConsole($client, '/');
        $this->openLoginPanel($client);
        $this->waitForTurnstileToken($client);

        $client->submit($crawler->filter('#connexion')->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
        $client->waitFor('header nav');
        $this->assertNoBrowserErrors($client, 'connexion');
    }

    /**
     * Dernière lecture de la console, après chaque test : une erreur arrivée
     * après le dernier contrôle (requête, contrôleur Stimulus, iframe) n'est
     * pas perdue. Panther a déjà pris sa capture d'échec (tearDown passe
     * avant) : elle est prise ici.
     *
     * Puis le navigateur quitte la page : rien de ce qu'elle lancerait encore
     * (préchargement de Turbo, requête en cours, minuteur, iframe) ne tombe
     * sur la base que le test suivant réinitialise. La console est vidée de ce
     * que ce départ a pu y laisser.
     */
    #[After]
    protected function assertNoBrowserErrorsAtTheEnd(): void
    {
        $client = self::$pantherClient;
        if (!$client instanceof Client) {
            return;
        }

        try {
            $this->assertNoBrowserErrors($client, 'fin du test');
        } catch (AssertionFailedError $assertionFailedError) {
            ServerExtensionLegacy::takeScreenshots('failure', $this->toString());

            throw $assertionFailedError;
        } finally {
            $driver = $client->getWebDriver();
            $driver->get('about:blank');
            $driver->manage()->getLog('browser');
        }
    }

    /**
     * Clic sur « Se connecter » de l'en-tête : le panneau de connexion s'ouvre.
     */
    private function openLoginPanel(Client $client): void
    {
        $this->driver($client)->findElement(WebDriverBy::cssSelector('header button[popovertarget="connexion"]'))->click();
        $client->wait(5)->until(static fn (RemoteWebDriver $driver): bool => true === $driver->executeScript('return document.getElementById("connexion").matches(":popover-open");'));
    }

    private function waitForTurnstileToken(Client $client): void
    {
        $client->wait(15)->until(static fn (RemoteWebDriver $driver): bool => '' !== $driver->executeScript(
            'return document.querySelector(\'input[name="cf-turnstile-response"]\')?.value ?? "";',
        ));
    }

    /**
     * Le Chrome de Panther, pour les gestes de souris et de clavier.
     */
    private function driver(Client $client): RemoteWebDriver
    {
        $driver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $driver);

        return $driver;
    }

    /**
     * Largeur arrondie, en pixels, du premier élément qui répond au sélecteur.
     */
    private function widthOf(Client $client, string $selector): int
    {
        $width = $client->executeScript(\sprintf('return Math.round(document.querySelector(%s).getBoundingClientRect().width);', json_encode($selector, \JSON_THROW_ON_ERROR)));
        self::assertIsInt($width);

        return $width;
    }
}
