<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\TurnstileSiteverifyMock;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ResetPasswordTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NEW_PASSWORD = 'nouveau-mot-de-passe';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testRequestSendsAResetLinkToTheAccount(): void
    {
        $this->requestPasswordReset(self::EMAIL);

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'From', 'Stopcast <no-reply@stopcast.local>');
        self::assertEmailHtmlBodyContains($email, 'http://localhost/reset-password/reset/');
        self::assertEmailTextBodyContains($email, 'http://localhost/reset-password/reset/');

        $this->assertConfirmationPage();
    }

    public function testRequestFormCarriesTheTurnstileWidget(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        self::assertCount(1, $crawler->filter('main form[name="reset_password_request"] [data-controller="turnstile"][data-turnstile-site-key-value="1x00000000000000000000AA"]'));
    }

    public function testRequestWithAnInvalidTurnstileTokenSendsNothing(): void
    {
        $this->requestPasswordReset(self::EMAIL, TurnstileSiteverifyMock::INVALID_TOKEN);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'La vérification anti-robot a échoué. Réessayez.');
        self::assertQueuedEmailCount(0);
    }

    public function testUnknownEmailShowsTheSamePageWithoutSendingAnything(): void
    {
        $this->requestPasswordReset('inconnu@example.com');

        self::assertQueuedEmailCount(0);
        $this->assertConfirmationPage();
    }

    public function testSecondRequestWithinTheHourSendsNothingMore(): void
    {
        $this->requestPasswordReset(self::EMAIL);
        self::assertQueuedEmailCount(1);

        $this->requestPasswordReset(self::EMAIL);

        self::assertQueuedEmailCount(0);
        $this->assertConfirmationPage();
    }

    public function testLinkFromTheEmailLetsTheUserChooseANewPassword(): void
    {
        $this->followResetLink($this->requestResetLink());
        $this->submitNewPassword(self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Votre mot de passe a été modifié, vous pouvez vous connecter.');
        self::assertSelectorExists('input[name="_password"]');
        // Un compte déjà actif ne date que ses connexions.
        self::assertNull($this->storedUser(self::EMAIL)->getLastLoginAt());

        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);
        self::assertResponseRedirects('/');

        $this->logIn(self::EMAIL, self::NEW_PASSWORD);
        self::assertResponseRedirects('/devices');
    }

    public function testTooShortPasswordIsRefused(): void
    {
        $this->followResetLink($this->requestResetLink());
        $this->submitNewPassword('court', 'court');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Le mot de passe doit faire au moins 12 caractères.');
    }

    public function testInvalidTokenIsRefused(): void
    {
        $this->followResetLink('/reset-password/reset/'.str_repeat('x', 40));

        $this->assertLinkRefused('Le lien de réinitialisation du mot de passe n\'est pas valide.');
    }

    public function testLinkCannotBeUsedTwice(): void
    {
        $link = $this->requestResetLink();
        $this->followResetLink($link);
        $this->submitNewPassword(self::NEW_PASSWORD, self::NEW_PASSWORD);

        $this->followResetLink($link);

        $this->assertLinkRefused('Le lien de réinitialisation du mot de passe n\'est pas valide.');
    }

    public function testLinkExpiresAfterAnHour(): void
    {
        $link = $this->requestResetLink();

        self::mockTime('+1 hour');
        $this->followResetLink($link);

        $this->assertLinkRefused('Le lien dans votre e-mail est expiré.');
    }

    public function testLinkIsStillValidJustBeforeTheHour(): void
    {
        $link = $this->requestResetLink();

        self::mockTime('+59 minutes');
        $this->followResetLink($link);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="reset_password[newPassword][first]"]');
    }

    /**
     * Retour sur la demande de lien, avec le motif du refus.
     */
    private function assertLinkRefused(string $reason): void
    {
        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', $reason);
        self::assertSelectorExists('input[name="reset_password_request[email]"]');
    }

    /**
     * Page affichée après toute demande, que le compte existe ou non.
     */
    private function assertConfirmationPage(): void
    {
        self::assertResponseRedirects('/reset-password/check-email');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Si un compte existe pour cette adresse, un email a été envoyé.');
        self::assertSelectorTextContains('main', 'valable 1 heure');
    }

    /**
     * Demande un lien pour le compte et le retrouve dans l'email envoyé.
     */
    private function requestResetLink(): string
    {
        $this->requestPasswordReset(self::EMAIL);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        if (1 !== preg_match('#http://localhost(/reset-password/reset/\S+)#', (string) $email->getTextBody(), $matches)) {
            self::fail('Aucun lien de réinitialisation dans l\'email.');
        }

        return $matches[1];
    }

    private function followResetLink(string $link): void
    {
        $this->client->request('GET', $link);

        // Le jeton quitte l'URL pour la session.
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();
    }

    private function submitNewPassword(string $password, string $confirmation): void
    {
        self::assertResponseIsSuccessful();

        $this->client->submit($this->client->getCrawler()->selectButton('Enregistrer')->form([
            'reset_password[newPassword][first]' => $password,
            'reset_password[newPassword][second]' => $confirmation,
        ]));
    }
}
