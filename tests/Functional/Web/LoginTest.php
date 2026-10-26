<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LoginTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testLoginFromTheShowcaseOpensTheDevices(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/devices');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Mes dispositifs');
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
        self::assertSame('/devices', $crawler->selectLink('Ouvrir mon espace')->attr('href'));
    }

    public function testLogoutReturnsToTheShowcase(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->logOut();

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testLogoutByGetIsRefusedAndKeepsTheSession(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(405);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutWithoutCsrfTokenKeepsTheSession(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        $this->client->request('POST', '/logout');

        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousVisitorIsSentToTheShowcase(): void
    {
        $this->client->request('GET', '/account');

        self::assertResponseRedirects('/');
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
