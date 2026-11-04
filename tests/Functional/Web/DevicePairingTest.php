<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Appairage d'un écran vu par le propriétaire : l'état de l'écran sur la
 * fiche du dispositif, la saisie du code et la dissociation.
 */
final class DevicePairingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string TOO_MANY_ATTEMPTS = "Trop d'essais. Réessayez plus tard.";

    private KernelBrowser $client;

    private User $user;

    private Device $device;

    protected function setUp(): void
    {
        $this->client = self::createClient(server: ['HTTPS' => 'on']);
        // Le compteur d'essais et les demandes d'appairage doivent survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        $this->user = UserFactory::createOne();
        $this->device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $this->client->loginUser($this->user);
    }

    public function testDeviceWithoutScreenOffersToPairOne(): void
    {
        $this->client->request('GET', $this->showPath());

        self::assertSelectorTextSame('[data-screen-state]', 'Aucun écran appairé');
        self::assertSelectorExists(\sprintf('a[href="%s"]:contains("Appairer un écran")', $this->pairPath()));
        self::assertSelectorNotExists('form[action$="/unpair"]');

        $this->client->request('GET', $this->pairPath());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Appairer un écran');
        self::assertSelectorNotExists('[data-replace-warning]');
    }

    public function testDeviceWithAScreenOffersToReplaceOrUnpairIt(): void
    {
        $this->pairAScreen();

        $this->client->request('GET', $this->showPath());

        self::assertSelectorTextSame('[data-screen-state]', 'Écran appairé');
        self::assertSelectorExists(\sprintf('a[href="%s"]:contains("Remplacer l\'écran")', $this->pairPath()));
        self::assertSelectorExists('form[action$="/unpair"] button:contains("Dissocier")');

        $this->client->request('GET', $this->pairPath());

        self::assertSelectorTextSame('h1', "Remplacer l'écran");
        self::assertSelectorTextContains('[data-replace-warning]', 'il sera déconnecté');
    }

    public function testUnpairingForgetsTheScreen(): void
    {
        $this->pairAScreen();
        $crawler = $this->client->request('GET', $this->showPath());

        $this->client->submit($crawler->selectButton('Dissocier')->form());

        self::assertResponseRedirects($this->showPath(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', "L'écran a été dissocié");
        self::assertFalse($this->storedDevice()->hasPairedScreen());
    }

    public function testUnpairingWithoutAValidCsrfTokenChangesNothing(): void
    {
        $this->pairAScreen();

        $this->client->request('POST', \sprintf('/devices/%d/unpair', $this->device->getId()), ['_token' => 'invalide']);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertTrue($this->storedDevice()->hasPairedScreen());
    }

    public function testMalformedCodeIsRefused(): void
    {
        $this->enterCode('0OI1L');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
    }

    public function testEntryIsBlockedAfterFiveWrongCodes(): void
    {
        $this->client->request('GET', '/display');
        $code = $this->screenCode();
        $this->client->loginUser($this->user);

        for ($i = 0; $i < 5; ++$i) {
            $this->enterCode('ZZZ-ZZZ');
            self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
        }

        // Même le bon code est refusé, et l'écran n'est pas appairé.
        $this->enterCode($code);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#pair_screen', self::TOO_MANY_ATTEMPTS);
        $this->client->request('GET', '/display/pairing');
        self::assertResponseIsSuccessful();
    }

    public function testCorrectCodeResetsTheWrongAttempts(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->enterCode('ZZZ-ZZZ');
        }

        $this->pairAScreen();

        for ($i = 0; $i < 4; ++$i) {
            $this->enterCode('ZZZ-ZZZ');
            self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
        }
    }

    /**
     * Un écran (le même client, sans cookie de dispositif) affiche un code, le
     * propriétaire le saisit, et l'écran récupère son cookie.
     */
    private function pairAScreen(): void
    {
        $this->client->request('GET', '/display');
        $this->enterCode($this->screenCode());
        self::assertResponseRedirects($this->showPath(), 303);

        $this->client->request('GET', '/display/pairing');
        self::assertResponseStatusCodeSame(205);
    }

    private function screenCode(): string
    {
        return $this->client->getCrawler()->filter('.pairing__code')->text();
    }

    private function enterCode(string $code): void
    {
        $crawler = $this->client->request('GET', $this->pairPath());
        $this->client->submit($crawler->selectButton('Appairer')->form(['pair_screen[code]' => $code]));
    }

    private function storedDevice(): Device
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $device = $entityManager->find(Device::class, $this->device->getId());
        self::assertInstanceOf(Device::class, $device);

        return $device;
    }

    private function showPath(): string
    {
        return \sprintf('/devices/%d', $this->device->getId());
    }

    private function pairPath(): string
    {
        return \sprintf('/devices/%d/pair', $this->device->getId());
    }
}
