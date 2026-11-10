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
use Symfony\Component\BrowserKit\Cookie;
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

    public function testThereIsNoConfigurationsListAnymore(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $configuration = $this->configure($device, 'Premier');

        $this->client->request('GET', '/display-configurations');
        self::assertResponseStatusCodeSame(404);

        // Une configuration se gère depuis son dispositif : ses pages relèvent du menu « Dispositifs ».
        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        self::assertCount(0, $crawler->filter('header nav')->selectLink("Configurations d'affichage"));
        self::assertSelectorTextSame('header nav [aria-current="page"]', 'Dispositifs');
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

        self::assertSame(['TROISIÈME', 'PREMIER', 'DEUXIÈME'], $this->displayedQuays($device));
    }

    public function testDeprecatedQuayWithoutPassageLeavesTheRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        self::assertSame(['PREMIER'], $this->displayedQuays($device));
    }

    public function testDeviceWhoseQuaysAllDisappearedShowsItsStateScreen(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        self::assertNotContains('VIEUX QUAI', $this->displayedQuays($device));
        self::assertSelectorTextSame('.screen__message', 'Aucun quai affiché');
    }

    public function testDeletingRemovesTheConfigurationFromTheRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $form = $crawler->filter('main li')->eq(0)->selectButton('Supprimer')->form();
        self::assertSame('Supprimer le quai « Premier » ? Cette action est définitive.', $form->getFormNode()->getAttribute('data-turbo-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le quai « Premier » a été supprimé.');
        self::assertSame(['DEUXIÈME'], $this->displayedQuays($device));
    }

    public function testDevicePageIsRefreshedInPlaceAfterARotationAction(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        self::assertSame('morph', $crawler->filter('head meta[name="turbo-refresh-method"]')->attr('content'));
        self::assertSame('preserve', $crawler->filter('head meta[name="turbo-refresh-scroll"]')->attr('content'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rotationButtons(): iterable
    {
        yield 'montée' => ['Monter'];
        yield 'descente' => ['Descendre'];
        yield 'masquage' => ['Afficher'];
        yield 'suppression' => ['Supprimer'];
    }

    #[DataProvider('rotationButtons')]
    public function testRotationActionReturnsToTheDevice(string $button): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $this->client->submit($crawler->filter('main li')->eq('Monter' === $button ? 1 : 0)->selectButton($button)->form());

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
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
        yield 'masquage' => ['POST', '/display-configurations/%d/visibility'];
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
        $configuration = $this->reload($configuration);
        self::assertSame(1, $configuration->getPosition());
        self::assertTrue($configuration->isActive());
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
        yield 'masquage sans jeton' => ['/display-configurations/%d/visibility', ['active' => '0']];
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
        $configuration = $this->reload($configuration);
        self::assertSame(1, $configuration->getPosition());
        self::assertTrue($configuration->isActive());
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

    public function testAddingAQuayTargetsTheDeviceFromTheUrl(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/new?device=%d', $device->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Ajouter un quai');
        self::assertSelectorTextContains('main', 'Sur le dispositif « Salon ».');
        $form = $crawler->selectButton('Ajouter')->form();
        self::assertArrayNotHasKey('display_configuration[device]', $form->all());
        self::assertSame(['quay', 'label', 'active'], array_values(array_map(
            static fn (string $name): string => (string) preg_replace('/^display_configuration\[(\w+)\].*$/', '$1', $name),
            array_filter(array_keys($form->all()), static fn (string $name): bool => !str_contains($name, '_token')),
        )));
        self::assertSame('switch', $crawler->filter('#display_configuration_active')->attr('role'));
        self::assertNotNull($crawler->filter('#display_configuration_active')->attr('checked'));
        self::assertSame(\sprintf('/devices/%d', $device->getId()), $crawler->selectLink('Annuler')->attr('href'));
    }

    public function testAddingAQuayWithoutDeviceIsNotFound(): void
    {
        $this->client->request('GET', '/display-configurations/new');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNewConfigurationJoinsTheEndOfItsDevicesRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $gare = $this->quay('S3', 'GARE');

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $device->getId()]);
        $form = $crawler->selectButton('Ajouter')->form();
        $form->disableValidation();

        $this->client->submit($form, [
            'display_configuration[quay]' => (string) $gare->getId(),
            'display_configuration[label]' => 'Vers la gare',
        ]);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le quai a été ajouté.');
        self::assertSame(['PREMIER', 'GARE'], $this->displayedQuays($device));
    }

    public function testAddingAQuayHiddenKeepsItOutOfTheRotation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $gare = $this->quay('S3', 'GARE');

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $device->getId()]);
        $form = $crawler->selectButton('Ajouter')->form();
        $form->disableValidation();

        $active = $form['display_configuration[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $this->client->submit($form, ['display_configuration[quay]' => (string) $gare->getId()]);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $configuration = $this->entityManager()->getRepository(DisplayConfiguration::class)->findOneBy([]);
        self::assertInstanceOf(DisplayConfiguration::class, $configuration);
        self::assertFalse($configuration->isActive());
    }

    public function testAddingAQuayToAnotherUsersDeviceIsForbidden(): void
    {
        $othersDevice = DeviceFactory::createOne();
        $gare = $this->quay('S3', 'GARE');

        $this->client->request('GET', '/display-configurations/new', ['device' => $othersDevice->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', \sprintf('/display-configurations/new?device=%d', $othersDevice->getId()), ['display_configuration' => ['quay' => (string) $gare->getId()]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->entityManager()->getRepository(DisplayConfiguration::class)->count());
    }

    public function testCreatingWithoutAQuayIsRefused(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);

        $crawler = $this->client->request('GET', '/display-configurations/new', ['device' => $device->getId()]);
        $this->client->submit($crawler->selectButton('Ajouter')->form());

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
        self::assertSelectorTextContains('h1', 'Modifier le quai');
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertArrayNotHasKey('display_configuration[device]', $form->all());
        self::assertInstanceOf(ChoiceFormField::class, $form['display_configuration[active]']);
        self::assertSame('switch', $crawler->filter('#display_configuration_active')->attr('role'));
        $active = $form['display_configuration[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $form['display_configuration[label]'] = 'Vers la gare';
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le quai a été modifié.');
        self::assertSelectorTextContains('main li:nth-child(2)', 'Vers la gare');
        self::assertSame('false', $crawler->filter('main li')->eq(1)->filter('[role="switch"]')->attr('aria-checked'));
        self::assertSame(['PREMIER'], $this->displayedQuays($device));
    }

    public function testSwitchHidesThenShowsTheConfiguration(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $switch = $crawler->filter('main li')->eq(1)->filter('[role="switch"]');
        self::assertSame('true', $switch->attr('aria-checked'));
        self::assertStringContainsString('Afficher Deuxième', $switch->text());
        $this->client->submit($crawler->filter('main li')->eq(1)->selectButton('Afficher Deuxième')->form());

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('false', $crawler->filter('main li')->eq(1)->filter('[role="switch"]')->attr('aria-checked'));
        $this->client->submit($crawler->filter('main li')->eq(1)->selectButton('Afficher Deuxième')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('true', $crawler->filter('main li')->eq(1)->filter('[role="switch"]')->attr('aria-checked'));

        self::assertSame(['PREMIER', 'DEUXIÈME'], $this->displayedQuays($device));
    }

    public function testHidingTwiceKeepsTheConfigurationHidden(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $form = $crawler->filter('main li')->eq(1)->selectButton('Afficher Deuxième')->form();
        $this->client->submit($form);
        $this->client->submit($form);

        self::assertSame(['PREMIER'], $this->displayedQuays($device));
    }

    public function testEditPageDeletesTheConfigurationAndReturnsToTheDevice(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $configuration = $this->configure($device, 'Deuxième');

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        self::assertSelectorTextContains('main dl', $device->getLabel());
        $form = $crawler->selectButton('Supprimer')->form();
        self::assertStringContainsString('Deuxième', $form->getFormNode()->getAttribute('data-turbo-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        self::assertSame(['PREMIER'], $this->displayedQuays($device));
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

    public function testConfigurationOnADeprecatedQuayIsFlaggedAndExplainedInARow(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        $row = $crawler->filter('main li')->eq(0);
        self::assertStringContainsString('quay-deprecated', (string) $row->attr('class'));
        self::assertStringContainsString('Quai disparu', $row->text());
        self::assertStringNotContainsString('Quai VIEUX QUAI', $row->text());
        self::assertStringNotContainsString('Quai absent du dernier import', $row->text());

        $info = $row->filter('button[popovertarget]:not([popovertargetaction])');
        self::assertCount(1, $info);
        self::assertStringContainsString('VIEUX QUAI', $info->text());

        $message = $row->filter('#'.$info->attr('popovertarget'));
        self::assertSame('', $message->attr('popover'));
        self::assertSame('dialog', $message->attr('role'));
        self::assertSame('Quai « VIEUX QUAI » disparu', $row->filter('#'.$message->attr('aria-labelledby'))->text());
        self::assertStringContainsString("Ce quai n'est plus dans les données du réseau. Le dispositif ne l'affiche que si des passages sont encore annoncés. Vous pouvez le conserver, au cas où il reviendrait, ou le supprimer.", $message->text());

        $keep = $message->selectButton('Conserver');
        self::assertSame($info->attr('popovertarget'), $keep->attr('popovertarget'));
        self::assertSame('hide', $keep->attr('popovertargetaction'));
        self::assertNotNull($keep->attr('autofocus'));
    }

    public function testDeletingFromTheMessageSkipsTheConfirmation(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier');
        $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $form = $crawler->filter('main li [popover]')->selectButton('Supprimer')->form();
        self::assertFalse($form->getFormNode()->hasAttribute('data-turbo-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Le quai « Ancien arrêt » a été supprimé.');
        self::assertSame(['PREMIER'], $this->displayedQuays($device));
    }

    public function testConfigurationOnAnActiveQuayHasNoFlagNorMessage(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, 'Premier', $this->quay('S1', 'MAIRIE'));

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        $row = $crawler->filter('main li')->eq(0);
        self::assertStringNotContainsString('quay-deprecated', (string) $row->attr('class'));
        self::assertStringContainsString('Quai MAIRIE', $row->text());
        self::assertCount(0, $row->filter('[popover], [popovertarget]'));
    }

    public function testRowBecomesNormalWhenTheQuayComesBack(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $quay = $this->quay('S9', 'VIEUX QUAI', deprecated: true);
        $this->configure($device, 'Ancien arrêt', $quay);

        $entityManager = $this->entityManager();
        $entityManager->find(Quay::class, $quay->getId())?->markActive();
        $entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        $row = $crawler->filter('main li')->eq(0);
        self::assertStringNotContainsString('quay-deprecated', (string) $row->attr('class'));
        self::assertStringContainsString('Quai VIEUX QUAI', $row->text());
    }

    public function testHiddenConfigurationOnADeprecatedQuayKeepsAReadableName(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $configuration = $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));
        $configuration->setActive(false);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        self::assertStringContainsString('text-slate-600', (string) $crawler->filter('main li [data-label]')->attr('class'));
    }

    public function testConfigurationOnADeprecatedQuayStaysEditableWithoutChangingQuay(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $configuration = $this->configure($device, 'Ancien arrêt', $this->quay('S9', 'VIEUX QUAI', deprecated: true));

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));
        self::assertSelectorTextContains('main .mb-6', 'Quai disparu');
        self::assertCount(0, $crawler->filter('main [popover]'));
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

        // Le quai porte le nom de la configuration : l'écran n'affiche que le nom du quai.
        $configuration = new DisplayConfiguration($device, $quay ?? $this->quay('S'.random_int(100, 999_999), mb_strtoupper($label)))->setLabel($label);
        $device->appendDisplayConfiguration($configuration);
        $entityManager->persist($configuration);
        $entityManager->flush();

        return $configuration;
    }

    /**
     * Quais que la page d'affichage montre à l'écran appairé du dispositif,
     * dans l'ordre de rotation.
     *
     * @return list<string>
     */
    private function displayedQuays(Device $device): array
    {
        $entityManager = $this->entityManager();
        $entityManager->find(Device::class, $device->getId())?->pairScreen('secret-de-l-ecran');
        $entityManager->flush();

        // Noyau neuf pour remplacer le flux, puis sans reboot pour qu'il reste en place.
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();

        $this->client->disableReboot();
        // Daté de maintenant : ce test tourne sur la vraie horloge, et un flux
        // à l'heure fixée du trait serait périmé.
        self::getContainer()->set('http_client', new MockHttpClient(self::feedResponse([], time())));

        $this->client->getCookieJar()->set(new Cookie('STOPCAST_DISPLAY', 'secret-de-l-ecran'));
        $crawler = $this->client->request('GET', '/display?fragment=1');

        self::assertResponseIsSuccessful();

        return $crawler->filter('.screen__stop')->each(static fn (Crawler $stop): string => $stop->text());
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
