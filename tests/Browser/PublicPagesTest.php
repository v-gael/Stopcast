<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Account\UserInviter;
use App\Factory\UserFactory;
use App\Tests\Support\BrowserTestTrait;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Pages publiques dans Chrome, sans session : aucune erreur de console ni
 * violation CSP.
 */
#[Group('browser')]
#[ResetDatabase]
final class PublicPagesTest extends PantherTestCase
{
    use BrowserTestTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'mot de passe oublié' => ['/reset-password'];
        yield "demande d'accès" => ['/demande-acces'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield "conditions d'utilisation" => ['/conditions-utilisation'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'sources des données' => ['/sources'];
    }

    #[DataProvider('pages')]
    public function testPageHasACleanConsole(string $path): void
    {
        $this->visitWithCleanConsole($this->anonymousClient(), $path);
    }

    /**
     * Le widget Turnstile (vrai script de Cloudflare, clé de test) n'est créé
     * qu'à l'ouverture du panneau de connexion, puis remplit son champ sans
     * violation CSP.
     */
    public function testLoginPanelGetsATurnstileTokenOnceOpened(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/');
        self::assertFalse($client->executeScript('return "turnstile" in window;'), "Le script de Turnstile est chargé avant l'ouverture du panneau.");

        $this->openLoginPanel($client);
        $this->waitForTurnstileToken($client);
        $this->assertNoBrowserErrors($client, 'widget Turnstile');
    }

    /**
     * Retour arrière vers la vitrine gardée par Turbo : panneau fermé, sans
     * widget recréé.
     */
    public function testShowcaseRestoredByTurboComesBackWithTheLoginPanelClosed(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/');
        $this->openLoginPanel($client);
        $this->waitForTurnstileToken($client);

        $client->executeScript('document.getElementById("connexion").hidePopover(); Turbo.visit("/mentions-legales");');
        $client->waitForElementToContain('main h1', 'Mentions légales');
        $client->executeScript('history.back();');
        $client->waitForElementToContain('main h1', 'Le panneau de votre arrêt');

        self::assertFalse($client->executeScript('return document.getElementById("connexion").matches(":popover-open");'));
        self::assertSame(0, $client->executeScript('return document.querySelectorAll(\'#connexion [data-controller~="turnstile"]\').length;'));
        $this->assertNoBrowserErrors($client, 'retour arrière vers la vitrine');
    }

    /**
     * Après un échec de connexion, la vitrine revient avec le panneau ouvert.
     */
    public function testFailedLoginReopensTheLoginPanel(): void
    {
        UserFactory::createOne(['email' => 'lea@example.com']);
        $client = $this->anonymousClient();
        $crawler = $this->visitWithCleanConsole($client, '/');
        $this->openLoginPanel($client);
        $this->waitForTurnstileToken($client);

        $client->submit($crawler->filter('#connexion')->selectButton('Se connecter')->form([
            '_username' => 'lea@example.com',
            '_password' => 'mauvais-mot-de-passe',
        ]));
        $client->waitFor('#connexion [role="alert"]');

        self::assertSelectorTextContains('#connexion [role="alert"]', 'Identifiants invalides.');
        self::assertTrue($client->executeScript('return document.getElementById("connexion").matches(":popover-open");'), "Le panneau ne s'est pas rouvert sur l'erreur.");
        $this->assertNoBrowserErrors($client, 'échec de connexion');
    }

    public function testInvitationPageHasACleanConsole(): void
    {
        $invitee = UserFactory::new()->pending()->create(['email' => 'paul@example.com']);
        // Le jeton que UserInviter mettrait dans l'email, que ce processus ne lit pas.
        // L'interface ne déclare pas la durée de vie, que la classe accepte.
        $resetPasswordHelper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelper::class, $resetPasswordHelper);
        $token = $resetPasswordHelper->generateResetToken($invitee, UserInviter::INVITATION_LIFETIME)->getToken();

        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/invitation/'.$token);

        self::assertSelectorTextContains('main', 'Choisissez votre mot de passe');
    }

    /**
     * Entre deux rafraîchissements, le contrôleur recompte les délais : un
     * passage qui arrive à son heure passe de « 2 min » au pictogramme du bus
     * à quai. L'écran de la vitrine est figé, l'heure du premier passage y est
     * ramenée à celle de l'écran.
     */
    public function testPassageReachingItsTimeSwitchesToTheBusAtStop(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/vitrine/ecran?mode=fixe');
        $passage = '[data-display-target="screen"]:not([hidden]) [data-display-target="passage"]:first-child';
        $client->waitForElementToContain($passage.' .passage__minutes', '2 min');
        self::assertFalse($client->findElement(WebDriverBy::cssSelector($passage.' .passage__at-stop'))->isDisplayed());

        $client->executeScript(\sprintf(
            'document.querySelector(%s).dataset.expectedAt = document.querySelector(\'[data-controller~="display"]\').dataset.displayNowValue;',
            json_encode($passage, \JSON_THROW_ON_ERROR),
        ));

        $client->waitForVisibility($passage.' .passage__at-stop');
        self::assertFalse($client->findElement(WebDriverBy::cssSelector($passage.' [data-display-target="countdown"]'))->isDisplayed());
        $this->assertNoBrowserErrors($client, 'bus à quai');
    }

    /**
     * Écran sans cookie : l'écran d'appairage demande un code.
     */
    public function testDisplayPairingScreenHasACleanConsole(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/display');

        $client->waitFor('.pairing__code');
        $this->assertNoBrowserErrors($client, "code d'appairage");
    }
}
