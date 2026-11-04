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
        $hidden = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Entrée']);
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Cuisine', 'active' => false]);
        DeviceFactory::createOne(['label' => "Chez quelqu'un d'autre"]);
        $this->configure($older);
        $this->configure($older);
        $this->configure($hidden, active: false);

        $crawler = $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('main [data-device-card]');
        self::assertSame(['Cuisine', 'Entrée', 'Salon'], $cards->each(static fn (Crawler $card): string => $card->filter('a')->text()));
        self::assertSame(['Inactif', 'Actif', 'Actif'], $cards->each(static fn (Crawler $card): string => $card->filter('[data-device-state]')->text()));
        self::assertStringContainsString('Aucun quai', $cards->eq(0)->text());
        self::assertStringContainsString('Tout est masqué', $cards->eq(1)->text());
        self::assertStringNotContainsString('Aucun quai', $cards->eq(2)->text());
        self::assertStringNotContainsString('Tout est masqué', $cards->eq(2)->text());
        self::assertSame(\sprintf('/devices/%d/preview', $older->getId()), $cards->eq(2)->filter('iframe')->attr('src'));
        self::assertStringContainsString('grayscale', (string) $cards->eq(0)->filter('iframe')->attr('class'));
    }

    public function testListAlwaysEndsWithTheAddBlock(): void
    {
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $crawler = $this->client->request('GET', '/devices');

        self::assertSame('/devices/new', $crawler->filter('main ul > li')->last()->filter('a')->attr('href'));
        self::assertSame('Ajouter un dispositif', $crawler->filter('main ul > li')->last()->filter('a')->text());
    }

    public function testListWithoutDevicesShowsOnlyTheAddBlock(): void
    {
        $crawler = $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main [data-device-card]'));
        self::assertSame('/devices/new', $crawler->selectLink('Ajouter un dispositif')->attr('href'));
    }

    public function testFiveDevicesFitOnePageWithoutSearchNorPagination(): void
    {
        $this->createDevices(5);

        $crawler = $this->client->request('GET', '/devices');

        self::assertCount(5, $crawler->filter('main [data-device-card]'));
        self::assertCount(0, $crawler->filter('main [role=search]'));
        self::assertCount(0, $crawler->filter('main nav[aria-label=Pagination]'));
    }

    public function testSixDevicesSpreadOverTwoPagesWithTheAddBlockOnEach(): void
    {
        $this->createDevices(6);

        $crawler = $this->client->request('GET', '/devices');

        self::assertSame(['Écran 6', 'Écran 5', 'Écran 4', 'Écran 3', 'Écran 2'], $this->cardLabels($crawler));
        self::assertSame('/devices/new', $crawler->filter('main ul > li')->last()->filter('a')->attr('href'));
        self::assertCount(1, $crawler->filter('main [role=search]'));
        self::assertSame('Page 1', $crawler->filter('nav[aria-label=Pagination] [aria-current=page]')->text());
        self::assertSame('/devices?page=2', $crawler->selectLink('Page 2')->attr('href'));

        $crawler = $this->client->click($crawler->selectLink('Suivant →')->link());

        self::assertSame(['Écran 1'], $this->cardLabels($crawler));
        self::assertSame('/devices/new', $crawler->filter('main ul > li')->last()->filter('a')->attr('href'));
        self::assertSame('Page 2', $crawler->filter('nav[aria-label=Pagination] [aria-current=page]')->text());
        self::assertCount(0, $crawler->selectLink('Suivant →'));
    }

    public function testSearchFiltersOnTheLabelAndKeepsItAcrossPages(): void
    {
        $this->createDevices(7);
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        DeviceFactory::createOne(['label' => 'Écran du voisin']);

        $crawler = $this->client->request('GET', '/devices?q=%20ÉCRAN%20');

        self::assertSame(['Écran 7', 'Écran 6', 'Écran 5', 'Écran 4', 'Écran 3'], $this->cardLabels($crawler));
        self::assertSame('ÉCRAN', $crawler->filter('#device-search')->attr('value'));
        self::assertSame('/devices?page=2&q=%C3%89CRAN', $crawler->selectLink('Suivant →')->attr('href'));

        $crawler = $this->client->request('GET', '/devices?q=salon');

        self::assertSame(['Salon'], $this->cardLabels($crawler));
        self::assertCount(0, $crawler->filter('main nav[aria-label=Pagination]'));
    }

    public function testSearchWithoutMatchSaysSoAndKeepsTheAddBlock(): void
    {
        $this->createDevices(6);

        $crawler = $this->client->request('GET', '/devices?q=100%25');

        self::assertSelectorTextContains('main', 'Aucun dispositif ne correspond à « 100% ».');
        self::assertCount(0, $crawler->filter('main [data-device-card]'));
        self::assertSame('/devices/new', $crawler->selectLink('Ajouter un dispositif')->attr('href'));
    }

    public function testSearchIsIgnoredWhileEverythingFitsOnePage(): void
    {
        $this->createDevices(2);

        $crawler = $this->client->request('GET', '/devices?q=introuvable');

        self::assertCount(2, $crawler->filter('main [data-device-card]'));
    }

    public function testPageBeyondTheLastRedirectsToTheLast(): void
    {
        $this->createDevices(6);

        $this->client->request('GET', '/devices?page=9&q=cran');

        self::assertResponseRedirects('/devices?page=2&q=cran');
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
        yield "saisie d'un code d'appairage" => ['GET', '/devices/%d/pair'];
        yield "envoi d'un code d'appairage" => ['POST', '/devices/%d/pair'];
        yield "dissociation de l'écran" => ['POST', '/devices/%d/unpair'];
    }

    #[DataProvider('deviceActions')]
    public function testAnotherUsersDeviceIsForbiddenEvenToAnAdministrator(string $method, string $path): void
    {
        $this->client->loginUser(UserFactory::createOne(['role' => Role::Admin]));
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
        self::assertSelectorTextContains('[data-flash-stack] [data-controller="flash"] [role="status"]', 'Le dispositif a été créé.');
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

    public function testNewDeviceHasTheDefaultCadences(): void
    {
        $crawler = $this->client->request('GET', '/devices/new');
        $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => 'Écran de la cuisine']));

        $device = $this->entityManager()->getRepository(Device::class)->findOneBy(['label' => 'Écran de la cuisine']);
        self::assertInstanceOf(Device::class, $device);
        self::assertSame([Device::DEFAULT_SCREEN_DURATION, Device::DEFAULT_REFRESH_INTERVAL], [$device->getScreenDuration(), $device->getRefreshInterval()]);
    }

    public function testEditingChangesTheCadences(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);

        $this->edit($device, ['device[screenDuration]' => '12', 'device[refreshInterval]' => '120']);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $device = $this->reload($device);
        self::assertSame([12, 120], [$device?->getScreenDuration(), $device?->getRefreshInterval()]);

        $this->client->followRedirect();
        self::assertSelectorTextSame('[data-screen-duration]', '12 s');
        self::assertSelectorTextSame('[data-refresh-interval]', '120 s');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cadencesOutOfBounds(): iterable
    {
        yield 'écran trop court' => ['screenDuration', '2', '60'];
        yield 'écran trop long' => ['screenDuration', '61', '60'];
        yield 'rafraîchissement trop fréquent' => ['refreshInterval', '5', '29'];
        yield 'rafraîchissement trop espacé' => ['refreshInterval', '5', '301'];
    }

    #[DataProvider('cadencesOutOfBounds')]
    public function testCadencesOutOfBoundsAreRefused(string $field, string $screenDuration, string $refreshInterval): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);

        $this->edit($device, ['device[screenDuration]' => $screenDuration, 'device[refreshInterval]' => $refreshInterval]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('#device_%s_error1', $field));
        $device = $this->reload($device);
        self::assertSame([Device::DEFAULT_SCREEN_DURATION, Device::DEFAULT_REFRESH_INTERVAL], [$device?->getScreenDuration(), $device?->getRefreshInterval()]);
    }

    public function testEditingWithAnEmptyLabelIsRefused(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $this->edit($device, ['device[label]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#device_label_error1');
        self::assertSame('Salon', $this->reload($device)?->getLabel());
    }

    public function testTimingsAreASliderAndPresets(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'screenDuration' => 8, 'refreshInterval' => 120]);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d/edit', $device->getId()));

        $slider = $crawler->filter('#device_screenDuration');
        self::assertSame(['range', '3', '60', '8'], [$slider->attr('type'), $slider->attr('min'), $slider->attr('max'), $slider->attr('value')]);
        self::assertSame('8 s', $crawler->filter('output[for="device_screenDuration"]')->text());

        $group = $crawler->filter('[role="group"][aria-labelledby="device_refreshInterval_label"]');
        self::assertSame(['30 s', '1 min', '2 min', '5 min', 'Autre'], $group->filter('button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame(['30', '60', '120', '300'], $group->filter('button[data-value]')->each(static fn (Crawler $button): ?string => $button->attr('data-value')));
        // Le champ nombre reste la donnée envoyée, et la seule commande sans JavaScript.
        self::assertSame(['number', '120'], [$crawler->filter('#device_refreshInterval')->attr('type'), $crawler->filter('#device_refreshInterval')->attr('value')]);
    }

    public function testEditFormShowsATestScreenFollowingTheInput(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon', 'screenDuration' => 8]);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d/edit', $device->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('iframe'));
        $bench = $crawler->filter('[data-controller="device-bench"]');
        self::assertSame('8', $bench->attr('data-device-bench-saved-duration-value'));
        self::assertSame('Salon', $bench->filter('[data-device-bench-target="labelOutput"]')->text());
        self::assertCount(3, $bench->filter('[data-device-bench-target="screen"]'));
        self::assertCount(2, $bench->filter('[data-device-bench-target="screen"][hidden]'));
        self::assertSame('labelInput', $crawler->filter('#device_label')->attr('data-device-bench-target'));
        self::assertSame('durationInput', $crawler->filter('#device_screenDuration')->attr('data-device-bench-target'));
    }

    public function testNewFormShowsTheTestScreenAtTheDefaultDuration(): void
    {
        $crawler = $this->client->request('GET', '/devices/new');

        $bench = $crawler->filter('[data-controller="device-bench"]');
        self::assertSame((string) Device::DEFAULT_SCREEN_DURATION, $bench->attr('data-device-bench-saved-duration-value'));
        self::assertCount(3, $bench->filter('[data-device-bench-target="screen"]'));
        self::assertSame('labelInput', $crawler->filter('#device_label')->attr('data-device-bench-target'));
        self::assertSame('/devices', $crawler->selectLink('Annuler')->attr('href'));
    }

    public function testDetailWithoutQuaySaysItHasNothingToShow(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));

        self::assertSelectorTextContains('main', "Aucun quai : le dispositif n'a rien à afficher.");
        self::assertSame(\sprintf('/display-configurations/new?device=%d', $device->getId()), $crawler->selectLink('Ajouter un quai à Salon')->attr('href'));
    }

    public function testDetailShowsTheStateWithTheCreationDateOnHover(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $created = 'Créé le '.$device->getCreatedAt()->format('d/m/Y à H:i');

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $state = $crawler->filter('[data-device-state]');
        self::assertSame($created, $state->attr('title'));
        self::assertStringStartsWith('Actif', $state->text());
        self::assertStringContainsString(mb_strtolower($created), $state->text());

        $entityManager = $this->entityManager();
        $entityManager->find(Device::class, $device->getId())?->setActive(false);
        $entityManager->flush();
        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        self::assertStringStartsWith('Inactif', $crawler->filter('[data-device-state]')->text());
    }

    public function testDisabledDeviceIsRejectedByTheApi(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->callApi($device->getToken());
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d/edit', $device->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertInstanceOf(ChoiceFormField::class, $form['device[active]']);
        self::assertSame('switch', $crawler->filter('#device_active')->attr('role'));
        $form['device[active]']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $crawler = $this->client->followRedirect();
        self::assertStringStartsWith('Inactif', $crawler->filter('[data-device-state]')->text());

        $this->callApi($device->getToken());
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegeneratedTokenReplacesTheOldOne(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $oldToken = $device->getToken();

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $device->getId()));
        $form = $crawler->selectButton('Régénérer le jeton')->form();
        // Pas un rafraîchissement Turbo : la page s'affiche en haut, avec le nouveau jeton.
        self::assertSame('advance', $form->getFormNode()->getAttribute('data-turbo-action'));
        $this->client->submit($form);

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

    private function createDevices(int $count): void
    {
        for ($number = 1; $number <= $count; ++$number) {
            DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Écran '.$number]);
        }
    }

    /**
     * @return list<string>
     */
    private function cardLabels(Crawler $crawler): array
    {
        return $crawler->filter('main [data-device-card]')->each(static fn (Crawler $card): string => $card->filter('a')->text());
    }

    private function configure(Device $device, ?string $label = null, int $position = 0, bool $active = true): void
    {
        $device = $this->reload($device);
        self::assertInstanceOf(Device::class, $device);

        $entityManager = $this->entityManager();
        $quay = $entityManager->getRepository(Quay::class)->findOneBy([]) ?? new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager->persist($quay);
        $entityManager->persist(new DisplayConfiguration($device, $quay)->setLabel($label)->setPosition($position)->setActive($active));
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
