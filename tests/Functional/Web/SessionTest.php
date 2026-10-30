<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SessionTest extends WebTestCase
{
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

    public function testSessionAndRememberMeCookiesAreHttpOnlyAndSameSiteLax(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);

        $session = $this->responseCookie($this->client->getRequest()->getSession()->getName());
        $rememberMe = $this->responseCookie('REMEMBERME');

        self::assertInstanceOf(Cookie::class, $session);
        self::assertSame(0, $session->getExpiresTime());
        foreach ([$session, $rememberMe] as $cookie) {
            self::assertInstanceOf(Cookie::class, $cookie);
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        }
    }

    public function testPasswordChangeLogsOutTheOtherBrowsers(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);
        $other = $this->otherBrowser();
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);
        $other->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->useBrowser($this->client);
        $this->changePassword();

        $this->useBrowser($other);
        $other->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    public function testRememberMeCookieNoLongerLogsInAfterAPasswordChange(): void
    {
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);
        $rememberMe = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertInstanceOf(BrowserCookie::class, $rememberMe);

        // Le cookie seul, sans session, rouvre l'espace avant le changement.
        $other = $this->otherBrowser();
        $other->getCookieJar()->set($rememberMe);
        $other->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->useBrowser($this->client);
        $this->changePassword();

        $other = $this->otherBrowser();
        $other->getCookieJar()->set($rememberMe);
        $other->request('GET', '/account');
        self::assertResponseRedirects('/');
    }

    private function changePassword(): void
    {
        $crawler = $this->client->request('GET', '/account/password');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'change_password[currentPassword]' => UserFactory::DEFAULT_PASSWORD,
            'change_password[newPassword][first]' => self::NEW_PASSWORD,
            'change_password[newPassword][second]' => self::NEW_PASSWORD,
        ]));
        self::assertResponseRedirects('/account', 303);
    }

    /**
     * Un second navigateur sur le même noyau, avec ses propres cookies,
     * devenu le client courant des gestes et des assertions.
     */
    private function otherBrowser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->client->getKernel());
        $this->useBrowser($browser);

        return $browser;
    }

    private function useBrowser(KernelBrowser $browser): void
    {
        self::getClient($browser);
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
