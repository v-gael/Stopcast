<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Quay;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminUserDeletionTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private KernelBrowser $client;

    private User $admin;

    private Quay $quay;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager->persist($this->quay);
        $entityManager->flush();

        $this->admin = UserFactory::createOne(['email' => 'admin@example.com', 'role' => Role::Admin]);
        $this->client->loginUser($this->admin);
    }

    public function testAdministratorDeletesAnAccountWithItsDevices(): void
    {
        $lea = UserFactory::createOne(['email' => self::EMAIL]);
        $token = $this->createDeviceWithConfiguration($lea);
        $this->createDeviceWithConfiguration($lea);
        $adminToken = $this->createDeviceWithConfiguration($this->admin);

        $form = $this->deleteForm(self::EMAIL);
        self::assertSame('Supprimer le compte lea@example.com et ses 2 dispositifs ? Cette action est définitive.', $form->getFormNode()->getAttribute('data-turbo-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Votre compte Abribus a été supprimé');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le compte lea@example.com a été supprimé.');

        UserFactory::assert()->notExists(['email' => self::EMAIL]);
        DeviceFactory::assert()->count(1);
        DeviceFactory::assert()->exists(['token' => $adminToken]);
        DisplayConfigurationFactory::assert()->count(1);

        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testConfirmationNamesTheDevicesToDelete(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->createDeviceWithConfiguration(UserFactory::createOne(['email' => 'bob@example.com']));

        self::assertSame('Supprimer le compte lea@example.com ? Cette action est définitive.', $this->deleteForm(self::EMAIL)->getFormNode()->getAttribute('data-turbo-confirm'));
        self::assertSame('Supprimer le compte bob@example.com et son dispositif ? Cette action est définitive.', $this->deleteForm('bob@example.com')->getFormNode()->getAttribute('data-turbo-confirm'));
    }

    public function testDeletingAPendingAccountSendsNoEmail(): void
    {
        UserFactory::new()->pending()->create(['email' => self::EMAIL]);

        $this->client->submit($this->deleteForm(self::EMAIL));

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(0);
        UserFactory::assert()->notExists(['email' => self::EMAIL]);
    }

    public function testDeletionWithoutAValidCsrfTokenDeletesNothing(): void
    {
        $lea = UserFactory::createOne(['email' => self::EMAIL]);

        $this->client->request('POST', \sprintf('/admin/users/%d/delete', $lea->getId()), ['_token' => 'invalide']);

        // Jeton CSRF invalide : Security renvoie vers la connexion.
        self::assertResponseRedirects('http://localhost/');
        self::assertQueuedEmailCount(0);
        UserFactory::assert()->exists(['email' => self::EMAIL]);
    }

    public function testAdministratorCannotDeleteTheirOwnAccountFromTheList(): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertCount(0, $this->accountRow($crawler, 'admin@example.com')->selectButton('Supprimer'));

        $this->client->request('POST', \sprintf('/admin/users/%d/delete', $this->admin->getId()), ['_token' => $this->csrfToken('delete-user-'.$this->admin->getId())]);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Supprimez votre propre compte depuis « Mon compte ».');
        UserFactory::assert()->exists(['email' => 'admin@example.com']);
    }

    public function testDeletionIsForbiddenToAUser(): void
    {
        $lea = UserFactory::createOne(['email' => self::EMAIL]);
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', \sprintf('/admin/users/%d/delete', $lea->getId()));

        self::assertResponseStatusCodeSame(403);
        UserFactory::assert()->exists(['email' => self::EMAIL]);
    }

    private function deleteForm(string $email): Form
    {
        $crawler = $this->client->request('GET', '/admin/users');

        return $this->accountRow($crawler, $email)->selectButton('Supprimer')->form();
    }

    private function createDeviceWithConfiguration(User $owner): string
    {
        $device = DeviceFactory::createOne(['owner' => $owner]);
        DisplayConfigurationFactory::createOne(['device' => $device, 'quay' => $this->quay]);

        return $device->getToken();
    }
}
