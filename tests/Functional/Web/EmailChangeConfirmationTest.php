<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Confirmation d'un changement d'email depuis le lien reçu à la nouvelle
 * adresse, et ses effets sur la connexion.
 */
final class EmailChangeConfirmationTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NEW_EMAIL = 'lea.nouvelle@example.com';

    private const string INVALID_LINK = "Ce lien de confirmation n'est plus valable.";

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testOpeningTheLinkChangesNothing(): void
    {
        $link = $this->requestChangeAndForgetSession();

        $this->client->request('GET', $link);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Confirmer le changement vers '.self::NEW_EMAIL);
        self::assertSame(self::EMAIL, $this->storedUser(self::EMAIL)->getEmail());
    }

    public function testConfirmingWithoutSessionChangesTheEmailAndNotifiesTheOldAddress(): void
    {
        $this->confirm($this->requestChangeAndForgetSession());

        self::assertResponseRedirects('/', 303);
        self::assertSame(self::NEW_EMAIL, $this->storedUser(self::NEW_EMAIL)->getEmail());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(self::EMAIL, $email->getTo()[0]->getAddress());
        self::assertStringContainsString('l***@e***.com', (string) $email->getTextBody());
        self::assertStringNotContainsString(self::NEW_EMAIL, (string) $email->getTextBody());
        self::assertStringContainsString("Si vous n'êtes pas à l'origine de ce changement, contactez l'administrateur", (string) $email->getTextBody());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', \sprintf('Votre adresse est maintenant %s, connectez-vous avec elle.', self::NEW_EMAIL));
    }

    public function testConfirmingFromTheSessionOfTheAccountStaysConnected(): void
    {
        $this->confirm($this->requestChange());

        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', \sprintf('Votre adresse est maintenant %s.', self::NEW_EMAIL));
        self::assertSelectorTextContains('main dd', self::NEW_EMAIL);
    }

    public function testConfirmingFromTheSessionOfAnotherAccountLandsOnTheHomePage(): void
    {
        $link = $this->requestChangeAndForgetSession();
        $this->client->loginUser(UserFactory::createOne(['email' => 'paul@example.com']));

        $this->confirm($link);

        self::assertResponseRedirects('/', 303);
    }

    public function testOtherSessionsOfTheAccountAreDisconnected(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/account');

        $otherSession = $this->sessionCookie();
        $this->client->getCookieJar()->clear();

        $this->confirm($this->requestChange());

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($otherSession);
        $this->client->request('GET', '/account');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLoginWorksWithTheNewAddressOnly(): void
    {
        $this->confirm($this->requestChangeAndForgetSession());
        $this->client->getCookieJar()->clear();

        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);
        self::assertResponseRedirects('/');

        $this->logIn(self::NEW_EMAIL, UserFactory::DEFAULT_PASSWORD);
        self::assertResponseRedirects('/devices');
    }

    public function testLinkCannotBeReplayedAfterTheChange(): void
    {
        $link = $this->requestChangeAndForgetSession();
        $this->confirm($link);
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', $link);

        $this->assertInvalidLink();
    }

    public function testPostingAFormOpenedBeforeTheChangeIsRefused(): void
    {
        $link = $this->requestChangeAndForgetSession();
        $form = $this->client->request('GET', $link)->selectButton('Confirmer')->form();
        $this->client->submit($form);
        self::assertQueuedEmailCount(1);

        $this->client->submit($form);

        $this->assertInvalidLink();
    }

    public function testExpiredLinkIsRefused(): void
    {
        $link = $this->requestChangeAndForgetSession();
        self::mockTime(new \DateTimeImmutable('+61 minutes'));

        $this->client->request('GET', $link);

        $this->assertInvalidLink();
    }

    public function testAlteredLinkIsRefused(): void
    {
        $link = $this->requestChangeAndForgetSession();

        $this->client->request('GET', preg_replace('#_hash=.#', '_hash=~', $link));

        $this->assertInvalidLink();
    }

    public function testLinkWithoutSignatureIsRefused(): void
    {
        $this->client->request('GET', (string) strtok($this->requestChangeAndForgetSession(), '?'));

        $this->assertInvalidLink();
    }

    public function testAddressTakenInTheMeantimeIsRefused(): void
    {
        $link = $this->requestChangeAndForgetSession();
        UserFactory::createOne(['email' => self::NEW_EMAIL]);

        $this->client->request('GET', $link);

        $this->assertInvalidLink();
        self::assertSame(self::EMAIL, $this->storedUser(self::EMAIL)->getEmail());
    }

    public function testLinkOfADeletedAccountIsRefused(): void
    {
        $link = $this->requestChangeAndForgetSession();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->remove($this->storedUser(self::EMAIL));
        $entityManager->flush();

        $this->client->request('GET', $link);

        $this->assertInvalidLink();
    }

    public function testPostWithAnInvalidCsrfTokenChangesNothing(): void
    {
        $link = $this->requestChangeAndForgetSession();

        $this->client->request('POST', $link, ['form' => ['_token' => 'faux']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::EMAIL, $this->storedUser(self::EMAIL)->getEmail());
        self::assertQueuedEmailCount(0);
    }

    public function testConfirmationRemovesThePendingPasswordResets(): void
    {
        $link = $this->requestChangeAndForgetSession();
        $this->requestPasswordReset(self::EMAIL);
        $resetToken = $this->tokenFromEmail('#/reset-password/reset/([^\s]+)#');

        $this->confirm($link);

        $this->client->request('GET', '/reset-password/reset/'.$resetToken);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    /**
     * @return string le lien reçu à la nouvelle adresse (chemin et signature)
     */
    private function requestChange(): string
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/account/email');
        $this->client->submitForm('Envoyer le lien', [
            'change_email[newEmail]' => self::NEW_EMAIL,
            'change_email[currentPassword]' => UserFactory::DEFAULT_PASSWORD,
        ]);
        self::assertResponseRedirects('/account', 303);

        return $this->tokenFromEmail('#(/email-change/\S+)#');
    }

    /**
     * Comme un lien ouvert sur un autre appareil.
     */
    private function requestChangeAndForgetSession(): string
    {
        $link = $this->requestChange();
        $this->client->getCookieJar()->clear();

        return $link;
    }

    private function confirm(string $link): void
    {
        $this->client->request('GET', $link);
        $this->client->submitForm('Confirmer');
    }

    private function assertInvalidLink(): void
    {
        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', self::INVALID_LINK);
    }

    private function sessionCookie(): Cookie
    {
        $cookie = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }
}
