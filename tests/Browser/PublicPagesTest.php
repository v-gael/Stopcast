<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Account\UserInviter;
use App\Factory\UserFactory;
use App\Tests\Support\BrowserTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Pages publiques dans Chrome, sans session : aucune erreur de console ni
 * violation CSP.
 */
#[Group('browser')]
final class PublicPagesTest extends PantherTestCase
{
    use BrowserTestTrait;
    use Factories;
    use ResetDatabase;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'mot de passe oublié' => ['/reset-password'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield "conditions d'utilisation" => ['/conditions-utilisation'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'sources des données' => ['/sources'];
    }

    #[DataProvider('pages')]
    public function testPageHasACleanConsole(string $path): void
    {
        $this->visit($this->anonymousClient(), $path);
    }

    /**
     * Le widget Turnstile (vrai script de Cloudflare, clé de test) remplit son
     * champ sans violation CSP.
     */
    public function testLoginFormGetsATurnstileToken(): void
    {
        $client = $this->anonymousClient();
        $this->visit($client, '/');

        $this->waitForTurnstileToken($client);
        $this->assertNoBrowserErrors($client, 'widget Turnstile');
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
        $this->visit($client, '/invitation/'.$token);

        self::assertSelectorTextContains('main', 'Choisissez votre mot de passe');
    }

    /**
     * Écran sans cookie : l'écran d'appairage demande un code.
     */
    public function testDisplayPairingScreenHasACleanConsole(): void
    {
        $client = $this->anonymousClient();
        $this->visit($client, '/display');

        $client->waitFor('.pairing__code');
        $this->assertNoBrowserErrors($client, "code d'appairage");
    }
}
