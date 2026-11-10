<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\BrowserTestTrait;
use App\Tests\Support\ContainerServiceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Espace connecté dans Chrome, parcouru comme un administrateur le ferait :
 * aucune erreur de console ni violation CSP, et le JavaScript des pages
 * (Turbo, aperçu, autocomplétion) fonctionne.
 */
#[Group('browser')]
final class SpaceTest extends PantherTestCase
{
    use BrowserTestTrait;
    use ContainerServiceTrait;
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'admin@example.com';

    private const string COPY_BUTTON = 'button[data-clipboard-target="button"]';

    private Client $client;

    private Device $device;

    protected function setUp(): void
    {
        UserFactory::createOne(['email' => self::EMAIL, 'role' => Role::Admin]);
        $this->device = $this->deviceWithTwoQuays();

        $this->client = $this->anonymousClient();
        $this->logInFromShowcase($this->client, self::EMAIL, UserFactory::DEFAULT_PASSWORD);
    }

    /**
     * Turbo remplace la page sans la recharger : la variable posée avant le
     * clic survit. L'aperçu affiche les passages du flux simulé.
     */
    public function testTurboVisitOpensTheDeviceWithItsPreview(): void
    {
        $this->visitWithCleanConsole($this->client, '/devices');
        $this->markWindow();

        $this->client->clickLink('Cuisine');
        $this->client->waitForElementToContain('main h1', 'Cuisine');

        self::assertTrue($this->windowIsMarked(), 'La visite du dispositif a rechargé la page au lieu de passer par Turbo.');
        self::assertGreaterThan(0, $this->passagesInPreview());
        $this->assertNoBrowserErrors($this->client, 'page du dispositif');
    }

    /**
     * « Descendre » renvoie la page, que Turbo fusionne par morphing.
     */
    public function testMovingAQuayMorphsTheDevicePage(): void
    {
        $this->visitWithCleanConsole($this->client, $this->devicePath());
        self::assertSame(['MAIRIE', 'GARE'], $this->rotation());
        $this->markWindow();

        $this->client->getWebDriver()->findElement(WebDriverBy::cssSelector('main li:first-child button[title="Afficher plus tard dans la rotation"]'))->click();
        $this->client->wait(10)->until(fn (): bool => ['GARE', 'MAIRIE'] === $this->rotation());

        self::assertTrue($this->windowIsMarked(), "L'action a rechargé la page au lieu du morphing de Turbo.");
        $this->assertNoBrowserErrors($this->client, 'morphing de la page du dispositif');
    }

    /**
     * Tom Select remplace le champ, ouvre ses options et porte son CSS.
     */
    public function testQuayAutocompleteOffersQuaysWithItsStyles(): void
    {
        $this->visitWithCleanConsole($this->client, '/display-configurations/new?device='.$this->device->getId());

        $this->client->waitFor('.ts-control');
        $this->client->getWebDriver()->findElement(WebDriverBy::cssSelector('.ts-control input'))->sendKeys('mai');

        // Les options d'avant la recherche disparaissent quand sa réponse arrive :
        // attendre cette réponse évite qu'une requête survive à la fin du test.
        $this->client->wait(10)->until(fn (): bool => ['MAIRIE → Centre-ville'] === $this->client->executeScript(
            'return [...document.querySelectorAll(".ts-dropdown [data-selectable]")].map((option) => option.textContent.trim());',
        ));
        self::assertSame('absolute', $this->client->executeScript('return getComputedStyle(document.querySelector(".ts-dropdown")).position;'));
        $this->assertNoBrowserErrors($this->client, 'autocomplétion du quai');
    }

    /**
     * Le presse-papiers est simulé : Chrome headless refuse d'y écrire sans
     * autorisation.
     */
    public function testCopyButtonCopiesTheDisplayAddress(): void
    {
        $this->visitPairingPageWithCopyButton();
        $this->client->executeScript('navigator.clipboard.writeText = async (text) => { window.stopcastCopied = text; };');

        $this->client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::COPY_BUTTON))->click();

        $this->client->waitForElementToContain(self::COPY_BUTTON, 'Copié');
        self::assertSelectorTextSame('main [role="status"]', 'Copié');
        $copied = $this->client->executeScript('return window.stopcastCopied;');
        self::assertIsString($copied);
        self::assertStringEndsWith('/display', $copied);

        $this->client->waitForElementToContain(self::COPY_BUTTON, 'Copier');
        $this->assertNoBrowserErrors($this->client, "copie de l'adresse");
    }

    public function testCopyButtonSaysWhenTheBrowserRefuses(): void
    {
        $this->visitPairingPageWithCopyButton();
        $this->client->executeScript('navigator.clipboard.writeText = () => Promise.reject(new DOMException("refus", "NotAllowedError"));');

        $this->client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::COPY_BUTTON))->click();

        $this->client->waitForElementToContain(self::COPY_BUTTON, 'Copie impossible');
        self::assertSelectorTextSame('main [role="status"]', 'Copie impossible');
        $this->assertNoBrowserErrors($this->client, 'copie refusée');
    }

    /**
     * Le QR code prend sa taille de l'espace connecté, sans la feuille de
     * style de l'écran d'appairage.
     */
    public function testPairingPageSizesTheQrCode(): void
    {
        $this->visitWithCleanConsole($this->client, $this->devicePath().'/pair');

        self::assertSame(128, $this->client->executeScript('return Math.round(document.querySelector(\'main [role="img"] svg\').getBoundingClientRect().width);'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherPages(): iterable
    {
        yield 'nouveau dispositif' => ['/devices/new', 'Ajouter un dispositif'];
        yield 'compte' => ['/account', 'Mon compte'];
        yield 'utilisateurs' => ['/admin/users', 'Utilisateurs'];
    }

    #[DataProvider('otherPages')]
    public function testPageOfTheSpaceHasACleanConsole(string $path, string $heading): void
    {
        $this->visitWithCleanConsole($this->client, $path);

        self::assertSelectorTextContains('main h1', $heading);
    }

    private function visitPairingPageWithCopyButton(): void
    {
        $this->visitWithCleanConsole($this->client, $this->devicePath().'/pair');
        $this->client->waitForVisibility(self::COPY_BUTTON);
    }

    private function devicePath(): string
    {
        return '/devices/'.$this->device->getId();
    }

    /**
     * Le dispositif de l'administrateur, avec deux quais desservis par une
     * ligne : le flux simulé leur sert des passages.
     */
    private function deviceWithTwoQuays(): Device
    {
        $entityManager = $this->service(EntityManagerInterface::class);

        $route = new Route('R1', '12', 'E2001A', 'FFFFFF');
        $entityManager->persist($route);

        $device = $entityManager->find(Device::class, DeviceFactory::createOne([
            'owner' => UserFactory::find(['email' => self::EMAIL]),
            'label' => 'Cuisine',
        ])->getId());
        self::assertInstanceOf(Device::class, $device);

        foreach (['S1' => 'MAIRIE', 'S2' => 'GARE'] as $stopId => $name) {
            $quay = new Quay($stopId, $name, 38.5, -28.6, 'P'.$stopId)->setDestinationsServed(['Centre-ville']);
            $quay->addRoute($route);
            $entityManager->persist($quay);

            $configuration = new DisplayConfiguration($device, $quay);
            $device->appendDisplayConfiguration($configuration);
            $entityManager->persist($configuration);
        }

        $entityManager->flush();

        return $device;
    }

    /**
     * Noms des quais de la rotation, dans l'ordre de la page.
     *
     * @return list<string>
     */
    private function rotation(): array
    {
        $names = $this->client->executeScript('return [...document.querySelectorAll("main li [data-label]")].map((name) => name.textContent.trim());');
        self::assertIsArray($names);

        return array_values(array_filter($names, \is_string(...)));
    }

    /**
     * Passages affichés dans l'iframe d'aperçu, une fois chargée.
     */
    private function passagesInPreview(): int
    {
        $driver = $this->client->getWebDriver();
        $driver->switchTo()->frame($driver->findElement(WebDriverBy::cssSelector('main iframe')));
        $this->client->wait(10)->until(static fn (WebDriver $driver): bool => [] !== $driver->findElements(WebDriverBy::cssSelector('.passage')));
        $count = \count($driver->findElements(WebDriverBy::cssSelector('.passage')));
        $driver->switchTo()->defaultContent();

        return $count;
    }

    private function markWindow(): void
    {
        $this->client->executeScript('window.stopcastNoReload = true;');
    }

    private function windowIsMarked(): bool
    {
        return true === $this->client->executeScript('return window.stopcastNoReload === true;');
    }
}
