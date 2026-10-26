<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AccountTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NEW_PASSWORD = 'nouveau-mot-de-passe';

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::createOne(['email' => self::EMAIL]);
        $this->client->loginUser($this->user);
    }

    public function testAccountShowsTheEmailAndTheSpaceNavigation(): void
    {
        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main dd', self::EMAIL);
        self::assertSame('/account/password', $crawler->selectLink('Modifier mon mot de passe')->attr('href'));
        self::assertSame('/account', $crawler->filter('header nav')->selectLink('Mon compte')->attr('href'));
        self::assertSame('/devices', $crawler->filter('header nav')->selectLink('Dispositifs')->attr('href'));
        $logout = $crawler->filter('header nav')->selectButton('Se déconnecter')->form();
        self::assertSame('POST', $logout->getMethod());
        self::assertStringEndsWith('/logout', $logout->getUri());
    }

    public function testWrongCurrentPasswordIsRefused(): void
    {
        $this->changePassword('mauvais-mot-de-passe', self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Le mot de passe actuel est incorrect.');
        $this->assertCanLogInWith(UserFactory::DEFAULT_PASSWORD);
    }

    public function testTooShortPasswordIsRefused(): void
    {
        $this->changePassword(UserFactory::DEFAULT_PASSWORD, 'court', 'court');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Le mot de passe doit faire au moins 12 caractères.');
    }

    public function testMismatchedConfirmationIsRefused(): void
    {
        $this->changePassword(UserFactory::DEFAULT_PASSWORD, self::NEW_PASSWORD, 'autre-mot-de-passe');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Les deux mots de passe ne correspondent pas.');
    }

    public function testPasswordIsChangedAndTheNewOneOpensTheAccount(): void
    {
        $this->changePassword(UserFactory::DEFAULT_PASSWORD, self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Votre mot de passe a été modifié.');

        $this->assertCanLogInWith(self::NEW_PASSWORD);
    }

    private function changePassword(string $current, string $new, string $confirmation): void
    {
        $crawler = $this->client->request('GET', '/account/password');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'change_password[currentPassword]' => $current,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $confirmation,
        ]));
    }

    /**
     * Se déconnecte puis se reconnecte depuis la vitrine.
     */
    private function assertCanLogInWith(string $password): void
    {
        $this->logOut();

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => self::EMAIL,
            '_password' => $password,
        ]));

        self::assertResponseRedirects('/devices');
    }
}
