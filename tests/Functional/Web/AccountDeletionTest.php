<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Quay;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AccountDeletionTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    private KernelBrowser $client;

    private User $user;

    private Quay $quay;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager->persist($this->quay);
        $entityManager->flush();

        $this->user = UserFactory::createOne(['email' => self::EMAIL]);
    }

    public function testAccountLinksToTheDeletionPage(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSame('/account/delete', $crawler->selectLink('Supprimer mon compte')->attr('href'));
    }

    public function testDeletionPageAnnouncesTheNumberOfDevices(): void
    {
        DeviceFactory::createMany(2, ['owner' => $this->user]);
        DeviceFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/account/delete');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'votre compte et vos 2 dispositifs, avec leurs configurations');
        self::assertSelectorExists('form[data-turbo-confirm]');
    }

    public function testWrongPasswordDeletesNothing(): void
    {
        $this->createDeviceWithConfiguration($this->user);

        $this->client->loginUser($this->user);
        $this->deleteAccount('mauvais-mot-de-passe');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Le mot de passe actuel est incorrect.');
        UserFactory::assert()->exists(['email' => self::EMAIL]);
        DeviceFactory::assert()->count(1);
        DisplayConfigurationFactory::assert()->count(1);
        self::assertQueuedEmailCount(0);
    }

    public function testCorrectPasswordDeletesTheAccountAndItsDevices(): void
    {
        $token = $this->createDeviceWithConfiguration($this->user);
        $otherToken = $this->createDeviceWithConfiguration(UserFactory::createOne());

        $this->client->loginUser($this->user);
        $this->deleteAccount(UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/', 303);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHtmlBodyContains($email, 'Votre compte Abribus a été supprimé.');
        self::assertEmailTextBodyContains($email, "Si vous n'êtes pas à l'origine de cette action, contactez l'administrateur.");
        self::assertEmailHtmlBodyNotContains($email, 'href');
        self::assertEmailTextBodyNotContains($email, 'http');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Votre compte a été supprimé.');
        self::assertSelectorExists('input[name="_password"]');

        UserFactory::assert()->notExists(['email' => self::EMAIL]);
        DeviceFactory::assert()->count(1);
        DeviceFactory::assert()->exists(['token' => $otherToken]);
        DisplayConfigurationFactory::assert()->count(1);

        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('http://localhost/');
    }

    public function testLastAdminCanDeleteTheirAccount(): void
    {
        $admin = UserFactory::createOne(['email' => 'admin@example.com', 'role' => Role::Admin]);

        $this->client->loginUser($admin);
        $this->deleteAccount(UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/', 303);
        UserFactory::assert()->notExists(['email' => 'admin@example.com']);
    }

    public function testAnonymousCannotReachTheDeletionPage(): void
    {
        $this->client->request('GET', '/account/delete');

        self::assertResponseRedirects('http://localhost/');
    }

    private function deleteAccount(string $password): void
    {
        $crawler = $this->client->request('GET', '/account/delete');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Supprimer mon compte')->form([
            'delete_account[currentPassword]' => $password,
        ]));
    }

    /**
     * @return string le jeton du dispositif
     */
    private function createDeviceWithConfiguration(User $owner): string
    {
        $device = DeviceFactory::createOne(['owner' => $owner]);
        DisplayConfigurationFactory::createOne(['device' => $device, 'quay' => $this->quay]);

        return $device->getToken();
    }
}
