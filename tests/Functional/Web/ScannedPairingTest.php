<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\ScreenPairingTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Appairage d'un écran par son QR code : le téléphone ouvre
 * /devices/pair?code=…, le propriétaire choisit le dispositif et appaire.
 */
final class ScannedPairingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use ScreenPairingTrait;

    private const string UNKNOWN_CODE = 'Ce code est inconnu ou a expiré. Scannez le nouveau code affiché sur l\'écran.';

    private KernelBrowser $client;

    private MockClock $clock;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient(server: ['HTTPS' => 'on']);
        // Demandes d'appairage, compteur d'essais et session doivent survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        $this->clock = new MockClock('2026-11-04 08:00:00', date_default_timezone_get());
        self::getContainer()->set('clock', $this->clock);

        $this->user = UserFactory::createOne();
    }

    public function testAnonymousVisitorLogsInAndComesBackToThePage(): void
    {
        $this->client->request('GET', '/devices/pair?code=K7F3QX');
        self::assertResponseRedirects('/');

        $this->logIn($this->user->getEmail(), UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('https://localhost/devices/pair?code=K7F3QX');
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', "Appairer l'écran K7F-3QX");
    }

    public function testOpeningThePageDoesNotPairTheScreen(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/devices/pair?code='.str_replace('-', '', $code));

        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/display/pairing');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->storedDevice($device)->hasPairedScreen());
    }

    public function testSingleDeviceIsPreselectedAndPairsTheScreen(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/devices/pair?code='.str_replace('-', '', $code));

        self::assertSame((string) $device->getId(), $this->selectedDevice($crawler->selectButton('Appairer')->form()['scanned_pairing[device]']));

        $this->client->submit($crawler->selectButton('Appairer')->form());

        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', "Code accepté : l'écran affichera « Salon »");
        $this->receiveScreenCookie();
        self::assertTrue($this->storedDevice($device)->hasPairedScreen());
    }

    public function testDevicesAreListedWithTheirScreenStateAndNoneIsPreselected(): void
    {
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $kitchen = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Cuisine']);
        $kitchen->pairScreen('secret-de-test');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/devices/pair?code=K7F3QX');

        self::assertSame(['Cuisine (écran appairé)', 'Salon'], $crawler->filter('#scanned_pairing_device label')->each(static fn ($label): string => $label->text()));
        self::assertNull($this->selectedDevice($crawler->selectButton('Appairer')->form()['scanned_pairing[device]']));
    }

    public function testExpiredCodeIsRefused(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);
        $this->clock->sleep(121);

        $this->pairScannedCode($code, $device);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="scanned_pairing"]', self::UNKNOWN_CODE);
    }

    public function testCodeServesOnlyOnce(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);
        $this->pairScannedCode($code, $device);
        self::assertResponseRedirects();

        $this->pairScannedCode($code, $device);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="scanned_pairing"]', self::UNKNOWN_CODE);
    }

    public function testWrongScannedCodesCountWithTheTypedOnes(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);

        for ($i = 0; $i < 4; ++$i) {
            $this->enterCode('ZZZ-ZZZ', $device);
        }

        $this->pairScannedCode('ZZZ-ZZZ', $device);
        self::assertSelectorTextContains('form[name="scanned_pairing"]', self::UNKNOWN_CODE);

        $this->pairScannedCode($code, $device);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="scanned_pairing"]', "Trop d'essais. Réessayez plus tard.");
    }

    public function testDeviceOfAnotherAccountCannotBeChosen(): void
    {
        DeviceFactory::createOne(['owner' => $this->user]);
        $other = DeviceFactory::createOne(['owner' => UserFactory::createOne()]);
        $code = $this->displayedCode();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/devices/pair?code='.str_replace('-', '', $code));
        $form = $crawler->selectButton('Appairer')->form();
        $form->disableValidation();

        $choice = $form['scanned_pairing[device]'];
        self::assertInstanceOf(ChoiceFormField::class, $choice);
        $choice->setValue((string) $other->getId());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/display/pairing');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->storedDevice($other)->hasPairedScreen());
    }

    public function testMalformedCodeIsReportedWithoutAForm(): void
    {
        DeviceFactory::createOne(['owner' => $this->user]);
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/devices/pair?code=0OI1L');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', "Cette adresse ne contient pas de code d'appairage valide.");
        self::assertSelectorNotExists('form[name="scanned_pairing"]');
    }

    public function testUserWithoutDeviceCreatesOneThenComesBackWithItChecked(): void
    {
        $code = str_replace('-', '', $this->displayedCode());
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/devices/pair?code='.$code);

        self::assertSelectorTextContains('main', "Vous n'avez pas encore de dispositif.");
        self::assertSelectorNotExists('form[name="scanned_pairing"]');
        $crawler = $this->client->click($crawler->selectLink('Créer un dispositif')->link());
        self::assertSame('https://localhost/devices/new?pair='.$code, $this->client->getRequest()->getUri());

        $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => 'Entrée']));

        $device = DeviceFactory::repository()->findOneBy(['label' => 'Entrée']);
        self::assertInstanceOf(Device::class, $device);
        self::assertResponseRedirects(\sprintf('/devices/pair?code=%s&device=%d', $code, $device->getId()), 303);
        $crawler = $this->client->followRedirect();
        self::assertSame((string) $device->getId(), $this->selectedDevice($crawler->selectButton('Appairer')->form()['scanned_pairing[device]']));
    }

    public function testInvalidPairParameterIsIgnoredOnCreation(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/devices/new?pair=https://example.com');
        $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => 'Entrée']));

        $device = DeviceFactory::repository()->findOneBy(['label' => 'Entrée']);
        self::assertInstanceOf(Device::class, $device);
        self::assertResponseRedirects(\sprintf('/devices/%d', $device->getId()), 303);
    }

    /**
     * Valeur du bouton radio coché, null si aucun ne l'est.
     */
    private function selectedDevice(mixed $field): ?string
    {
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $value = $field->getValue();

        return \is_string($value) ? $value : null;
    }
}
