<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LastLoginTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NOW = '2026-10-31 09:30:00';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime(self::NOW);
    }

    public function testLoginRecordsItsDateAndCancelsTheInactivityWarning(): void
    {
        UserFactory::new()
            ->afterInstantiate(static function (User $user): void {
                $user->recordLogin(new \DateTimeImmutable('2024-11-30 10:00:00'));
                $user->markInactivityWarned(new \DateTimeImmutable('2026-10-30 03:15:00'));
            })
            ->create(['email' => self::EMAIL]);

        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/devices');
        $user = $this->storedUser(self::EMAIL);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $user->getLastLoginAt());
        self::assertNull($user->getInactivityWarnedAt());
    }

    public function testRememberMeLoginRecordsItsDate(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->logIn(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);
        $rememberMe = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertInstanceOf(Cookie::class, $rememberMe);

        // Le cookie seul, sans session, quelques jours plus tard.
        self::mockTime('+10 days');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($rememberMe);
        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertEquals(new \DateTimeImmutable(self::NOW)->modify('+10 days'), $this->storedUser(self::EMAIL)->getLastLoginAt());
    }

    public function testFailedLoginRecordsNothing(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->logIn(self::EMAIL, 'mauvais-mot-de-passe');

        self::assertNull($this->storedUser(self::EMAIL)->getLastLoginAt());
    }
}
