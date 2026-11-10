<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\ScreenPairingTrait;
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
    use ScreenPairingTrait;

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

    public function testPairingPageGivesTheDisplayAddressAsPlainText(): void
    {
        $this->client->request('GET', $this->pairPath());

        self::assertSelectorTextSame('[data-display-address]', 'https://localhost/display');
        self::assertSelectorNotExists('main a[href$="/display"]');
    }

    public function testPairingPageSaysTheAddressIsForTheScreenToPair(): void
    {
        $this->client->request('GET', $this->pairPath());

        self::assertSelectorTextContains('main', "Sur l'écran à appairer, et non sur cet appareil, ouvrez cette adresse.");
    }

    public function testPairingPageOffersAQrCodeForAScreenWithACamera(): void
    {
        $this->client->request('GET', $this->pairPath());

        self::assertSelectorExists('[role="img"][aria-label="QR code vers https://localhost/display"] svg');
        self::assertSelectorTextContains('main', "L'écran est un téléphone ou une tablette ? Scannez ce QR code avec lui.");
    }

    /**
     * Le JavaScript ne le montre que si le navigateur permet la copie.
     */
    public function testCopyButtonStartsHidden(): void
    {
        $this->client->request('GET', $this->pairPath());

        self::assertSelectorExists('button[data-clipboard-target="button"][hidden]');
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
        self::assertSelectorTextSame('[data-display-address]', 'https://localhost/display');
        self::assertSelectorExists('[role="img"][aria-label="QR code vers https://localhost/display"] svg');
    }

    public function testUnpairingForgetsTheScreen(): void
    {
        $this->pairAScreen();
        $crawler = $this->client->request('GET', $this->showPath());

        $this->client->submit($crawler->selectButton('Dissocier')->form());

        self::assertResponseRedirects($this->showPath(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', "L'écran a été dissocié");
        self::assertFalse($this->storedDevice($this->device)->hasPairedScreen());
    }

    public function testUnpairingWithoutAValidCsrfTokenChangesNothing(): void
    {
        $this->pairAScreen();

        $this->client->request('POST', \sprintf('/devices/%d/unpair', $this->device->getId()), ['_token' => 'invalide']);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertTrue($this->storedDevice($this->device)->hasPairedScreen());
    }

    public function testMalformedCodeIsRefused(): void
    {
        $this->enterCode('0OI1L', $this->device);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
    }

    public function testEntryIsBlockedAfterFiveWrongCodes(): void
    {
        $code = $this->displayedCode();

        for ($i = 0; $i < 5; ++$i) {
            $this->enterCode('ZZZ-ZZZ', $this->device);
            self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
        }

        // Même le bon code est refusé, et l'écran n'est pas appairé.
        $this->enterCode($code, $this->device);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#pair_screen', self::TOO_MANY_ATTEMPTS);
        $this->client->request('GET', '/display/pairing');
        self::assertResponseIsSuccessful();
    }

    public function testCorrectCodeResetsTheWrongAttempts(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->enterCode('ZZZ-ZZZ', $this->device);
        }

        $this->pairAScreen();

        for ($i = 0; $i < 4; ++$i) {
            $this->enterCode('ZZZ-ZZZ', $this->device);
            self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
        }
    }

    /**
     * Un écran (le même client, sans cookie de dispositif) affiche un code, le
     * propriétaire le saisit, et l'écran récupère son cookie.
     */
    private function pairAScreen(): void
    {
        $this->enterCode($this->displayedCode(), $this->device);
        self::assertResponseRedirects($this->showPath(), 303);

        $this->receiveScreenCookie();
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
