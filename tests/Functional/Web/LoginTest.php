<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LoginTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testLoginFromTheShowcaseOpensTheAccount(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', self::EMAIL);
    }

    public function testFailedLoginShowsTheShowcaseAgainWithTheErrorAndTheEmail(): void
    {
        $this->logIn(self::EMAIL, 'mauvais-mot-de-passe');

        self::assertResponseRedirects('/');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#connexion [role="alert"]', 'Identifiants invalides.');
        self::assertSame(self::EMAIL, $crawler->filter('input[name="_username"]')->attr('value'));

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testLoginWithoutCsrfTokenIsRefused(): void
    {
        $this->client->request('POST', '/login', ['_username' => self::EMAIL, '_password' => UserFactory::DEFAULT_PASSWORD]);

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testRememberMeKeepsTheUserLoggedInForThirtyDays(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);

        $rememberMe = $this->responseCookie('REMEMBERME');
        self::assertInstanceOf(Cookie::class, $rememberMe);
        self::assertEqualsWithDelta(time() + 30 * 24 * 3600, $rememberMe->getExpiresTime(), 60);
    }

    public function testWithoutRememberMeNoCookieIsKept(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $rememberMe = $this->responseCookie('REMEMBERME');
        self::assertTrue(!$rememberMe instanceof Cookie || $rememberMe->isCleared());
    }

    public function testLoggedInUserSeesALinkToTheirSpaceInsteadOfTheForm(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('input[name="_password"]'));
        self::assertSame('/account', $crawler->selectLink('Ouvrir mon espace')->attr('href'));
    }

    public function testLogoutReturnsToTheShowcase(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testAnonymousVisitorIsSentToTheShowcase(): void
    {
        $this->client->request('GET', '/account');

        self::assertResponseRedirects('/');
    }

    private function logIn(string $email, string $password, bool $rememberMe = false): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]);

        if ($rememberMe) {
            $checkbox = $form['_remember_me'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }

        $this->client->submit($form);
    }

    private function responseCookie(string $name): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
