<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpClient\MockHttpClient;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DisplayConfigurationsTest extends WebTestCase
{
    use Factories;
    use RealtimeFeedTrait;
    use ResetDatabase;

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::createOne();
        $this->client->loginUser($this->user);
    }

    public function testListGroupsOwnConfigurationsByDeviceInRotationOrder(): void
    {
        $salon = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $cuisine = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Cuisine']);
        $this->configure($salon, 'Premier');
        $this->configure($salon, 'Deuxième')->setActive(false);
        $this->entityManager()->flush();
        $this->configure(DeviceFactory::createOne(['label' => 'Ailleurs']), "Chez quelqu'un d'autre");

        $crawler = $this->client->request('GET', '/display-configurations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header nav [aria-current="page"]', "Configurations d'affichage");
        $groups = $crawler->filter('main section')->each(static fn (Crawler $group): array => [
            $group->filter('h2')->text(),
            $group->filter('li [data-label]')->each(static fn (Crawler $label): string => $label->text()),
        ]);
        self::assertSame([['Cuisine', []], ['Salon', ['Premier', 'Deuxième']]], $groups);
        self::assertSame(
            \sprintf('/display-configurations/new?device=%d', $cuisine->getId()),
            $crawler->filter('main section')->first()->selectLink('Ajouter une configuration')->attr('href'),
        );
    }

    public function testMovingChangesTheOrderServedToTheDevice(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        foreach (['Premier', 'Deuxième', 'Troisième'] as $label) {
            $this->configure($device, $label);
        }

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $items = $crawler->filter('main li');
        self::assertCount(0, $items->eq(0)->selectButton('Monter'));
        self::assertCount(1, $items->eq(0)->selectButton('Descendre'));
        self::assertCount(1, $items->eq(2)->selectButton('Monter'));
        self::assertCount(0, $items->eq(2)->selectButton('Descendre'));

        $this->client->submit($items->eq(2)->selectButton('Monter')->form());
        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('main li')->eq(0)->selectButton('Descendre')->form());

        self::assertSame(['Troisième', 'Premier', 'Deuxième'], $this->displayedLabels($device));
    }

    public function testDeletingRemovesTheConfigurationFromTheRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $form = $crawler->filter('main li')->eq(0)->selectButton('Supprimer')->form();
        self::assertStringContainsString('Premier', $form->getFormNode()->getAttribute('data-turbo-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'La configuration « Premier » a été supprimée.');
        self::assertSame(['Deuxième'], $this->displayedLabels($device));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configurationActions(): iterable
    {
        yield 'édition' => ['GET', '/display-configurations/%d/edit'];
        yield 'enregistrement' => ['POST', '/display-configurations/%d/edit'];
        yield 'suppression' => ['POST', '/display-configurations/%d/delete'];
        yield 'montée' => ['POST', '/display-configurations/%d/move-up'];
        yield 'descente' => ['POST', '/display-configurations/%d/move-down'];
    }

    #[DataProvider('configurationActions')]
    public function testAnotherUsersConfigurationIsForbiddenEvenToAnAdministrator(string $method, string $path): void
    {
        $this->client->loginUser(UserFactory::createOne(['role' => Role::Admin]));
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $configuration = $this->configure($device, 'Deuxième');

        $this->client->request($method, \sprintf($path, $configuration->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->reload($configuration)->getPosition());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function forgedPosts(): iterable
    {
        yield 'suppression sans jeton' => ['/display-configurations/%d/delete', []];
        yield 'suppression avec un jeton invalide' => ['/display-configurations/%d/delete', ['_token' => 'invalide']];
        yield 'montée sans jeton' => ['/display-configurations/%d/move-up', []];
        yield 'descente sans jeton' => ['/display-configurations/%d/move-down', []];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('forgedPosts')]
    public function testPostWithoutAValidCsrfTokenChangesNothing(string $path, array $parameters): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $configuration = $this->configure($device, 'Deuxième');
        $this->configure($device, 'Troisième');

        $this->client->request('POST', \sprintf($path, $configuration->getId()), $parameters);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertSame(1, $this->reload($configuration)->getPosition());
    }

    public function testQuayAutocompleteOffersOnlyActiveQuaysWithTheirDestinations(): void
    {
        $mairie = $this->quay('S1', 'MAIRIE', ['Centre-ville', 'Hôtel de ville']);
        $this->quay('S2', 'MAIRIE ANNEXE', deprecated: true);
        $this->quay('S3', 'GARE');

        $this->client->request('GET', '/autocomplete/quay', ['query' => 'mairie']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['value' => (string) $mairie->getId(), 'text' => 'MAIRIE → Centre-ville, Hôtel de ville']],
            $this->autocompleteResults(),
        );
    }

    public function testQuayAutocompleteLabelsAQuayWithoutDestinationsByItsNameAlone(): void
    {
        $gare = $this->quay('S3', 'GARE');

        $this->client->request('GET', '/autocomplete/quay', ['query' => 'GARE']);

        self::assertSame([['value' => (string) $gare->getId(), 'text' => 'GARE']], $this->autocompleteResults());
    }

    public function testQuayAutocompleteIsClosedToAnonymousVisitors(): void
    {
        $this->quay('S1', 'MAIRIE');
        $this->client->restart();

        $this->client->request('GET', '/autocomplete/quay', ['query' => 'MAIRIE']);

        self::assertResponseRedirects('http://localhost/');
    }

    public function testNewConfigurationJoinsTheEndOfItsDevicesRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $gare = $this->quay('S3', 'GARE');

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $device->getId()]);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Créer')->form();
        self::assertInstanceOf(ChoiceFormField::class, $form['display_configuration[device]']);
        self::assertSame((string) $device->getId(), $form['display_configuration[device]']->getValue());
        $form->disableValidation();
        $this->client->submit($form, [
            'display_configuration[quay]' => (string) $gare->getId(),
            'display_configuration[label]' => 'Vers la gare',
        ]);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'La configuration a été créée.');
        self::assertSame(['Premier', 'Vers la gare'], $this->displayedLabels($device));
    }

    public function testCreatingWithAnotherUsersDeviceIsRefused(): void
    {
        $othersDevice = DeviceFactory::createOne();
        $gare = $this->quay('S3', 'GARE');

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $othersDevice->getId()]);
        $form = $crawler->selectButton('Créer')->form();
        self::assertInstanceOf(ChoiceFormField::class, $form['display_configuration[device]']);
        self::assertSame('', $form['display_configuration[device]']->getValue());
        self::assertStringNotContainsString($othersDevice->getLabel(), (string) $this->client->getResponse()->getContent());
        $form->disableValidation();
        $this->client->submit($form, [
            'display_configuration[device]' => (string) $othersDevice->getId(),
            'display_configuration[quay]' => (string) $gare->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#display_configuration_device_error1');
        self::assertSame(0, $this->entityManager()->getRepository(DisplayConfiguration::class)->count());
    }

    public function testCreatingWithoutAQuayIsRefused(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $device->getId()]);
        $this->client->submit($crawler->selectButton('Créer')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#display_configuration_quay_error1', 'Choisissez un quai.');
        self::assertSame(0, $this->entityManager()->getRepository(DisplayConfiguration::class)->count());
    }

    public function testMaskedConfigurationLeavesTheDevicesDisplays(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $crawler = $this->client->click($crawler->filter('main li')->eq(1)->selectLink('Modifier')->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertArrayNotHasKey('display_configuration[device]', $form->all());
        self::assertInstanceOf(ChoiceFormField::class, $form['display_configuration[active]']);
        $form['display_configuration[active]']->untick();
        $form['display_configuration[label]'] = 'Vers la gare';
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'La configuration a été modifiée.');
        self::assertSelectorTextContains('main li:nth-child(2)', 'Vers la gare');
        self::assertSelectorTextContains('main li:nth-child(2)', 'Masquée');
        self::assertSame(['Premier'], $this->displayedLabels($device));
    }

    public function testEditingChangesTheQuay(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $configuration = $this->configure($device, 'Arrêt');
        $gare = $this->quay('S3', 'GARE');

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form->disableValidation();

        $this->client->submit($form, ['display_configuration[quay]' => (string) $gare->getId()]);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        self::assertSame('GARE', $this->reload($configuration)->getQuay()->getName());
    }

    public function testConfigurationOnADeprecatedQuayStaysEditableWithoutChangingQuay(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $configuration = $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        $this->client->request('GET', '/display-configurations');
        self::assertSelectorTextContains('main li', 'Quai absent du dernier import');

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        self::assertSelectorTextContains('main', 'Quai absent du dernier import');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['display_configuration[label]' => 'Toujours là']));

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $reloaded = $this->reload($configuration);
        self::assertSame('Toujours là', $reloaded->getLabel());
        self::assertSame('VIEUX QUAI', $reloaded->getQuay()->getName());
    }

    public function testAnotherDeprecatedQuayIsStillRefusedOnEdit(): void
    {
        $configuration = $this->configure(DeviceFactory::createOne(['owner' => $this->user]), 'Arrêt');
        $otherDeprecated = $this->quay('S8', 'AUTRE VIEUX QUAI', deprecated: true);

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form->disableValidation();

        $this->client->submit($form, ['display_configuration[quay]' => (string) $otherDeprecated->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('AUTRE VIEUX QUAI', $this->reload($configuration)->getQuay()->getName());
    }

    private function reload(DisplayConfiguration $configuration): DisplayConfiguration
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $reloaded = $entityManager->find(DisplayConfiguration::class, $configuration->getId());
        self::assertInstanceOf(DisplayConfiguration::class, $reloaded);

        return $reloaded;
    }

    private function configure(Device $device, string $label, ?Quay $quay = null): DisplayConfiguration
    {
        $entityManager = $this->entityManager();
        $device = $entityManager->find(Device::class, $device->getId());
        self::assertInstanceOf(Device::class, $device);

        $configuration = new DisplayConfiguration($device, $quay ?? $this->quay('S'.random_int(100, 999_999), 'QUAI'))->setLabel($label);
        $device->appendDisplayConfiguration($configuration);
        $entityManager->persist($configuration);
        $entityManager->flush();

        return $configuration;
    }

    /**
     * Libellés servis au dispositif par l'API, dans l'ordre de rotation.
     *
     * @return list<string|null>
     */
    private function displayedLabels(Device $device): array
    {
        // Noyau neuf pour remplacer les services, puis sans reboot pour qu'ils restent en place.
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();

        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(self::feedResponse([])));
        $container->set('clock', new MockClock(self::NOW));

        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $device->getToken()]);

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertIsArray($response['displays']);

        /** @var list<string|null> $labels */
        $labels = array_column($response['displays'], 'label');

        return $labels;
    }

    /**
     * @param string[]|null $destinations
     */
    private function quay(string $stopId, string $name, ?array $destinations = null, bool $deprecated = false): Quay
    {
        $quay = new Quay($stopId, $name, 38.5, -28.6, 'P'.$stopId)->setDestinationsServed($destinations);

        if ($deprecated) {
            $quay->markDeprecated();
        }

        $entityManager = $this->entityManager();
        $entityManager->persist($quay);
        $entityManager->flush();

        return $quay;
    }

    /**
     * @return list<array{value: string, text: string}>
     */
    private function autocompleteResults(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsList($payload['results']);

        $results = [];

        foreach ($payload['results'] as $result) {
            self::assertIsArray($result);
            self::assertIsScalar($result['value']);
            self::assertIsString($result['text']);
            $results[] = ['value' => (string) $result['value'], 'text' => $result['text']];
        }

        return $results;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
