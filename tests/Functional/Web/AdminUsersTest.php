<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\Persistence\refresh;

final class AdminUsersTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->admin = UserFactory::createOne(['email' => 'admin@abribus.test', 'role' => User::ROLE_ADMIN]);
        $this->client->loginUser($this->admin);
    }

    public function testListShowsEveryAccountNewestFirst(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);
        $bob = UserFactory::createOne(['email' => 'bob@abribus.test', 'role' => User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/users');
        $rows = $this->rows($crawler);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header nav [aria-current="page"]', 'Utilisateurs');
        self::assertSame(['bob@abribus.test', 'alice@abribus.test', 'admin@abribus.test (vous)'], array_column($rows, 0));
        self::assertSame(['admin@abribus.test'], $crawler->filter('main tbody strong')->each(static fn (Crawler $email): string => $email->text()));
        self::assertSame(['Administrateur', 'Utilisateur', 'Administrateur'], array_column($rows, 1));
        self::assertSame(array_map(static fn (User $user): string => $user->getCreatedAt()->format('d/m/Y'), [$bob, $alice, $this->admin]), array_column($rows, 3));
    }

    public function testListCountsTheDevicesOfEachAccount(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);
        DeviceFactory::createMany(2, ['owner' => $alice]);
        DeviceFactory::createOne(['owner' => $this->admin, 'active' => false]);
        UserFactory::createOne(['email' => 'bob@abribus.test']);

        $rows = $this->rows($this->client->request('GET', '/admin/users'));

        self::assertSame(['bob@abribus.test', 'alice@abribus.test', 'admin@abribus.test (vous)'], array_column($rows, 0));
        self::assertSame(['0', '2', '1'], array_column($rows, 2));
    }

    public function testListLinksEveryAccountToItsEditPage(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertSame(
            [\sprintf('/admin/users/%d/edit', $alice->getId()), \sprintf('/admin/users/%d/edit', $this->admin->getId())],
            $crawler->filter('main tbody')->selectLink('Modifier')->each(static fn (Crawler $link): ?string => $link->attr('href')),
        );
    }

    public function testListIsForbiddenToAUser(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNavigationLinksToTheListForAnAdministrator(): void
    {
        $crawler = $this->client->request('GET', '/devices');

        self::assertSame('/admin/users', $crawler->filter('header nav')->selectLink('Utilisateurs')->attr('href'));
    }

    public function testNavigationHidesTheListFromAUser(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $crawler = $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('header nav')->selectLink('Utilisateurs'));
    }

    public function testAdministratorPromotesAUser(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);

        $this->changeRole($alice, User::ROLE_ADMIN);

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Rôle de alice@abribus.test modifié.');

        $this->client->loginUser(refresh($alice));
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
    }

    public function testAdministratorDemotesAnotherAdministrator(): void
    {
        $bob = UserFactory::createOne(['email' => 'bob@abribus.test', 'role' => User::ROLE_ADMIN]);

        $this->changeRole($bob, User::ROLE_USER);

        self::assertResponseRedirects('/admin/users', 303);

        $this->client->loginUser(refresh($bob));
        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdministratorWhoDemotesThemselvesIsLoggedOut(): void
    {
        $this->changeRole($this->admin, User::ROLE_USER);

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects('http://localhost/');
    }

    public function testOpenSessionsOfTheChangedAccountAreLoggedOut(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);
        $this->client->loginUser($alice);
        $aliceSession = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($aliceSession);
        // loginUser() réutilise la session du cookie : sans ce vidage, l'admin écraserait celle d'Alice.
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($this->admin);

        $this->changeRole($alice, User::ROLE_ADMIN);

        $this->client->getCookieJar()->set($aliceSession);
        $this->client->request('GET', '/devices');

        self::assertResponseRedirects('http://localhost/');
    }

    public function testEditFormShowsTheCurrentRoleAndNoEmailField(): void
    {
        $bob = UserFactory::createOne(['email' => 'bob@abribus.test', 'role' => User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $bob->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'bob@abribus.test');
        self::assertSame(User::ROLE_ADMIN, $crawler->filter('main form input[name="user_role[role]"]:checked')->attr('value'));
        self::assertCount(0, $crawler->filter('main form input[name*="email"]'));
    }

    public function testUnknownRoleIsRefused(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@abribus.test']);
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $alice->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form->disableValidation()->setValues(['user_role[role]' => 'ROLE_SUPER_ADMIN']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(User::ROLE_USER, refresh($alice)->getRole());
    }

    #[DataProvider('editMethods')]
    public function testEditIsForbiddenToAUser(string $method): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request($method, \sprintf('/admin/users/%d/edit', $this->admin->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function editMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
    }

    public function testEditOfAnUnknownAccountIsNotFound(): void
    {
        $this->client->request('GET', '/admin/users/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('main tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    private function changeRole(User $user, string $role): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $user->getId()));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer')->form(['user_role[role]' => $role]));
    }
}
