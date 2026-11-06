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
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverBy;
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
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'admin@example.com';

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
        $this->visit($this->client, '/devices');
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
        $this->visit($this->client, $this->devicePath());
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
        $this->visit($this->client, '/display-configurations/new?device='.$this->device->getId());

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

    public function testOtherPagesOfTheSpaceHaveACleanConsole(): void
    {
        foreach (['/devices/new', '/account', '/admin/users'] as $path) {
            $this->visit($this->client, $path);
        }

        // L'assertion de visit() suffit ; PHPUnit attend au moins une assertion par test.
        self::assertSelectorExists('main');
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
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

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
