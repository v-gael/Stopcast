<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\Persistence\repository;
use function Zenstruck\Foundry\Persistence\save;

final class InvitationTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string PASSWORD = 'mot-de-passe-choisi-1';

    private const string SPAM_REMINDER = 'Prévenez cette personne : le mail peut arriver dans ses courriers indésirables, et son lien expire après 24 heures.';

    private KernelBrowser $client;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->admin = UserFactory::createOne(['email' => 'admin@example.com', 'role' => Role::Admin]);
        $this->client->loginUser($this->admin);
    }

    public function testAdministratorInvitesAPerson(): void
    {
        $this->invite(self::EMAIL, Role::Admin);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Activez votre compte Stopcast');
        self::assertEmailAddressContains($email, 'Reply-To', 'contact@stopcast.local');
        self::assertEmailHtmlBodyContains($email, 'http://localhost/invitation/');
        self::assertEmailTextBodyContains($email, 'http://localhost/invitation/');
        self::assertEmailTextBodyContains($email, 'valable 24 heures');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Invitation envoyée à lea@example.com.');
        self::assertSelectorTextContains('[role="alert"]', self::SPAM_REMINDER);
        self::assertSame('Invitation en attente', $this->statusOf(self::EMAIL));

        $user = $this->findUser(self::EMAIL);
        self::assertTrue($user->isPending());
        self::assertSame(Role::Admin, $user->getRole());
    }

    public function testInviteFormProposesTheUserRoleByDefault(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/invite');

        self::assertResponseIsSuccessful();
        self::assertSame(Role::User->value, $crawler->filter('main form input[name="invite_user[role]"]:checked')->attr('value'));
    }

    public function testEmailAlreadyUsedIsRefused(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->invite(self::EMAIL, Role::User);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Un compte existe déjà avec cet email.');
        self::assertQueuedEmailCount(0);
    }

    public function testEmailDifferingOnlyByCaseIsRefused(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->invite(strtoupper(self::EMAIL), Role::User);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Un compte existe déjà avec cet email.');
        self::assertQueuedEmailCount(0);
    }

    public function testInviteIsForbiddenToAUser(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/admin/users/invite');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPendingAccountCannotLogIn(): void
    {
        UserFactory::new()->pending()->create(['email' => self::EMAIL]);
        $this->client->getCookieJar()->clear();

        $this->logIn(self::EMAIL, self::PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#connexion [role="alert"]', 'Identifiants invalides.');
    }

    public function testInviteeActivatesTheAccountWithTheLink(): void
    {
        $link = $this->inviteAndGetLink();

        $this->followInvitationLink($link);
        self::assertSelectorTextContains('main', 'Choisissez votre mot de passe');
        // Le jeton du lien protège déjà la page : pas de captcha.
        self::assertSelectorNotExists('[data-controller="turnstile"]');
        $this->choosePassword(self::PASSWORD);

        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Votre compte est activé, vous pouvez vous connecter.');
        // Page publique : le message reste dans le flux, au-dessus du formulaire.
        self::assertSelectorNotExists('[data-flash-stack], [data-controller="flash"]');

        $this->logIn(self::EMAIL, self::PASSWORD);
        self::assertResponseRedirects('/devices');

        $this->client->loginUser($this->admin);
        self::assertSame('Actif', $this->statusOf(self::EMAIL));
    }

    public function testActivationStartsTheActivityPeriodOfTheAccount(): void
    {
        self::mockTime('2026-10-31 09:30:00');
        $link = $this->inviteAndGetLink();
        self::assertNull($this->storedUser(self::EMAIL)->getLastLoginAt());

        self::mockTime('+2 hours');
        $this->followInvitationLink($link);
        $this->choosePassword(self::PASSWORD);

        self::assertEquals(new \DateTimeImmutable('2026-10-31 11:30:00'), $this->storedUser(self::EMAIL)->getLastLoginAt());
    }

    public function testInvitationLinkCannotBeUsedTwice(): void
    {
        $link = $this->inviteAndGetLink();
        $this->followInvitationLink($link);
        $this->choosePassword(self::PASSWORD);

        $this->followInvitationLink($link);

        $this->assertInvitationRefused();
    }

    public function testInvitationLinkIsStillValidJustBeforeADay(): void
    {
        $link = $this->inviteAndGetLink();

        self::mockTime('+23 hours 59 minutes');
        $this->followInvitationLink($link);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="reset_password[newPassword][first]"]');
    }

    public function testInvitationLinkExpiresAfterADay(): void
    {
        $link = $this->inviteAndGetLink();

        self::mockTime('+24 hours');
        $this->followInvitationLink($link);

        $this->assertInvitationRefused();
    }

    public function testInvalidInvitationLinkIsRefused(): void
    {
        $this->client->getCookieJar()->clear();

        $this->followInvitationLink('/invitation/'.str_repeat('x', 40));

        $this->assertInvitationRefused();
    }

    public function testActiveAccountIsSentToTheLoginForm(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->client->getCookieJar()->clear();
        $this->requestPasswordReset(self::EMAIL);
        $token = $this->tokenFromEmail('#http://localhost/reset-password/reset/(\S+)#');

        $this->followInvitationLink('/invitation/'.$token);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Votre compte est déjà activé, connectez-vous.');
    }

    public function testForgottenPasswordAlsoActivatesAPendingAccount(): void
    {
        self::mockTime('2026-10-31 09:30:00');
        UserFactory::new()->pending()->create(['email' => self::EMAIL]);
        $this->client->getCookieJar()->clear();
        $this->requestPasswordReset(self::EMAIL);
        $this->client->request('GET', '/reset-password/reset/'.$this->tokenFromEmail('#http://localhost/reset-password/reset/(\S+)#'));

        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'reset_password[newPassword][first]' => self::PASSWORD,
            'reset_password[newPassword][second]' => self::PASSWORD,
        ]));
        self::assertEquals(new \DateTimeImmutable('2026-10-31 09:30:00'), $this->storedUser(self::EMAIL)->getLastLoginAt());
        $this->logIn(self::EMAIL, self::PASSWORD);

        self::assertResponseRedirects('/devices');
    }

    public function testResendRightAfterTheInvitationReplacesTheLink(): void
    {
        $this->invite(self::EMAIL, Role::User);
        $oldLink = '/invitation/'.$this->tokenFromEmail('#http://localhost/invitation/(\S+)#');

        $this->resendInvitation(self::EMAIL);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Activez votre compte Stopcast');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Invitation renvoyée à lea@example.com.');
        self::assertSelectorTextContains('[role="alert"]', self::SPAM_REMINDER);

        $this->client->getCookieJar()->clear();
        $this->followInvitationLink($oldLink);
        $this->assertInvitationRefused();
    }

    public function testResentLinkActivatesTheAccount(): void
    {
        $this->invite(self::EMAIL, Role::User);
        $this->resendInvitation(self::EMAIL);
        $link = '/invitation/'.$this->tokenFromEmail('#http://localhost/invitation/(\S+)#');
        $this->client->getCookieJar()->clear();

        $this->followInvitationLink($link);
        $this->choosePassword(self::PASSWORD);
        $this->logIn(self::EMAIL, self::PASSWORD);

        self::assertResponseRedirects('/devices');
    }

    public function testResendWithoutAValidCsrfTokenIsRefused(): void
    {
        $user = UserFactory::new()->pending()->create(['email' => self::EMAIL]);

        $this->client->request('POST', \sprintf('/admin/users/%d/resend-invitation', $user->getId()), ['_token' => 'invalide']);

        // Jeton CSRF invalide : Security renvoie vers la connexion.
        self::assertResponseRedirects('http://localhost/');
        self::assertQueuedEmailCount(0);
    }

    public function testResendForAnAccountActivatedMeanwhileSendsNothing(): void
    {
        $user = UserFactory::new()->pending()->create(['email' => self::EMAIL]);
        $form = $this->accountRow($this->client->request('GET', '/admin/users'), self::EMAIL)->selectButton("Renvoyer l'invitation")->form();
        $user->setPassword('hash');
        save($user);

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'lea@example.com a déjà activé son compte : rien à renvoyer.');
        self::assertCount(0, $this->accountRow($crawler, self::EMAIL)->selectButton("Renvoyer l'invitation"));
    }

    public function testResendIsForbiddenToAUser(): void
    {
        $user = UserFactory::new()->pending()->create(['email' => self::EMAIL]);
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', \sprintf('/admin/users/%d/resend-invitation', $user->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Invite le compte puis se déconnecte : la suite se joue côté invité.
     */
    private function inviteAndGetLink(): string
    {
        $this->invite(self::EMAIL, Role::User);
        $this->client->getCookieJar()->clear();

        return '/invitation/'.$this->tokenFromEmail('#http://localhost/invitation/(\S+)#');
    }

    private function followInvitationLink(string $link): void
    {
        $this->client->request('GET', $link);

        // Le jeton quitte l'URL pour la session.
        self::assertResponseRedirects('/invitation');
        $this->client->followRedirect();
    }

    private function choosePassword(string $password): void
    {
        $this->client->submit($this->client->getCrawler()->selectButton('Activer mon compte')->form([
            'reset_password[newPassword][first]' => $password,
            'reset_password[newPassword][second]' => $password,
        ]));
    }

    /**
     * Retour sur « Mot de passe oublié », qui permet de demander un nouveau lien.
     */
    private function assertInvitationRefused(): void
    {
        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', "Ce lien d'invitation n'est plus valable. Demandez un nouveau lien ci-dessous.");
        self::assertSelectorExists('input[name="reset_password_request[email]"]');
    }

    private function resendInvitation(string $email): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($this->accountRow($crawler, $email)->selectButton("Renvoyer l'invitation")->form());
    }

    private function invite(string $email, Role $role): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $crawler = $this->client->click($crawler->selectLink('Inviter un compte')->link());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton("Envoyer l'invitation")->form([
            'invite_user[email]' => $email,
            'invite_user[role]' => $role->value,
        ]));
    }

    /**
     * Badge de statut de la ligne du compte dans la liste.
     */
    private function statusOf(string $email): string
    {
        return trim($this->accountRow($this->client->request('GET', '/admin/users'), $email)->filter('td')->eq(2)->text());
    }

    private function findUser(string $email): User
    {
        $user = repository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
