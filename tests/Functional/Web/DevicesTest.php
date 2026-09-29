<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DevicesTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::createOne();
        $this->client->loginUser($this->user);
    }

    public function testListShowsOnlyOwnDevicesNewestFirst(): void
    {
        $older = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Cuisine', 'active' => false]);
        DeviceFactory::createOne(['label' => "Chez quelqu'un d'autre"]);
        $this->configure($older);
        $this->configure($older);

        $crawler = $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('main tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame(['Cuisine', 'Salon'], array_column($rows, 0));
        self::assertSame(['Désactivé', 'Actif'], array_column($rows, 1));
        self::assertSame(['0', '2'], array_column($rows, 2));
    }

    public function testListWithoutDevicesInvitesToAddOne(): void
    {
        $crawler = $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', "Aucun dispositif pour l'instant.");
        self::assertSame('/devices/new', $crawler->selectLink('Ajouter un dispositif')->attr('href'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deviceActions(): iterable
    {
        yield 'détail' => ['GET', '/devices/%d'];
        yield 'édition' => ['GET', '/devices/%d/edit'];
        yield 'enregistrement' => ['POST', '/devices/%d/edit'];
        yield 'suppression' => ['POST', '/devices/%d/delete'];
        yield 'régénération du jeton' => ['POST', '/devices/%d/regenerate-token'];
    }

    #[DataProvider('deviceActions')]
    public function testAnotherUsersDeviceIsForbiddenEvenToAnAdministrator(string $method, string $path): void
    {
        $this->client->loginUser(UserFactory::createOne(['role' => User::ROLE_ADMIN]));
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $token = $device->getToken();

        $this->client->request($method, \sprintf($path, $device->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame($token, $this->reload($device)?->getToken());
    }

    public function testCreatedDeviceShowsItsTokenOnlyOnce(): void
    {
        $crawler = $this->client->request('GET', '/devices/new');
        $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => 'Écran de la cuisine']));

        $device = $this->entityManager()->getRepository(Device::class)->findOneBy(['label' => 'Écran de la cuisine']);
        self::assertInstanceOf(Device::class, $device);
        self::assertSame($this->user->getId(), $device->getOwner()->getId());
        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le dispositif a été créé.');
        self::assertSelectorTextSame('[data-new-token] code', $device->getToken());

        $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-new-token]');
        self::assertStringNotContainsString($device->getToken(), (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyLabelIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/devices/new');
        $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#device_label_error1');
        self::assertSame(0, $this->entityManager()->getRepository(Device::class)->count());
    }

    public function testEditingChangesTheLabel(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $this->edit($device, ['device[label]' => 'Chambre']);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        self::assertSame('Chambre', $this->reload($device)?->getLabel());
    }

    public function testEditingWithAnEmptyLabelIsRefused(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $this->edit($device, ['device[label]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#device_label_error1');
        self::assertSame('Salon', $this->reload($device)?->getLabel());
    }

    public function testDisabledDeviceIsRejectedByTheApi(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->callApi($device->getToken());
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d/edit', $device->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertInstanceOf(ChoiceFormField::class, $form['device[active]']);
        $form['device[active]']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main h1 + *', 'Désactivé');

        $this->callApi($device->getToken());
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegeneratedTokenReplacesTheOldOne(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $oldToken = $device->getToken();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $this->client->submit($crawler->selectButton('Régénérer le jeton')->form());

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        $newToken = $this->reload($device)?->getToken();
        self::assertIsString($newToken);
        self::assertNotSame($oldToken, $newToken);
        self::assertSelectorTextSame('[data-new-token] code', $newToken);

        $this->callApi($oldToken);
        self::assertResponseStatusCodeSame(401);
        $this->callApi($newToken);
        self::assertResponseIsSuccessful();
    }

    public function testDeletingRemovesTheDeviceAndItsConfigurations(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $this->configure($device);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $this->client->submit($crawler->filter(\sprintf('form[action="/devices/%d/delete"]', $device->getId()))->form());

        self::assertResponseRedirects('/devices', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le dispositif « Salon » a été supprimé.');
        self::assertSame(0, $this->entityManager()->getRepository(Device::class)->count());
        self::assertSame(0, $this->entityManager()->getRepository(DisplayConfiguration::class)->count());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function forgedPosts(): iterable
    {
        yield 'suppression sans jeton' => ['/devices/%d/delete', []];
        yield 'suppression avec un jeton invalide' => ['/devices/%d/delete', ['_token' => 'invalide']];
        yield 'régénération sans jeton' => ['/devices/%d/regenerate-token', []];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('forgedPosts')]
    public function testPostWithoutAValidCsrfTokenChangesNothing(string $path, array $parameters): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $token = $device->getToken();

        $this->client->request('POST', \sprintf($path, $device->getId()), $parameters);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertSame($token, $this->reload($device)?->getToken());
    }

    public function testDetailListsTheConfigurationsInRotationOrder(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Deuxième', 1);
        $this->configure($device, 'Premier', 0);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        self::assertSame(['Premier', 'Deuxième'], $crawler->filter('main ol li p.font-medium')->each(static fn (Crawler $item): string => $item->text()));
    }

    /**
     * @param array<string, string> $values
     */
    private function edit(Device $device, array $values): void
    {
        $crawler = $this->client->request('GET', \sprintf('/devices/%d/edit', $device->getId()));
        $this->client->submit($crawler->selectButton('Enregistrer')->form($values));
    }

    private function callApi(string $token): void
    {
        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $token]);
    }

    private function configure(Device $device, ?string $label = null, int $position = 0): void
    {
        $device = $this->reload($device);
        self::assertInstanceOf(Device::class, $device);

        $entityManager = $this->entityManager();
        $quay = $entityManager->getRepository(Quay::class)->findOneBy([]) ?? new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager->persist($quay);
        $entityManager->persist(new DisplayConfiguration($device, $quay)->setLabel($label)->setPosition($position));
        $entityManager->flush();
    }

    private function reload(Device $device): ?Device
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->find(Device::class, $device->getId());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
