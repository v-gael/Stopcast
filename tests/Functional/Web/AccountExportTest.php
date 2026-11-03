<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AccountExportTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private const string EMAIL = 'lea@example.com';

    private const string NOW = '2026-10-30 14:30:00';

    private const string LAST_LOGIN = '2026-10-01 08:15:00';

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::new()
            ->afterInstantiate(static fn (User $user) => $user->recordLogin(new \DateTimeImmutable(self::LAST_LOGIN)))
            ->create(['email' => self::EMAIL]);
    }

    public function testTheAccountOwnerDownloadsTheirDataAsAJsonFile(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $quay = $this->quay('S1', 'CENTRE VILLE');
        $this->configure($device, $quay, 'Vers la gare', active: false);
        $this->configure($device, $quay, 'Vers le port');
        DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Cuisine']);

        $data = $this->download();

        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="stopcast-donnees-2026-10-30.json"');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString("\n    \"account\"", (string) $this->client->getResponse()->getContent());

        self::assertSame(new \DateTimeImmutable(self::NOW)->format(\DATE_ATOM), $data['exportedAt']);
        self::assertSame([
            'email' => self::EMAIL,
            'role' => 'ROLE_USER',
            'createdAt' => $this->user->getCreatedAt()->format(\DATE_ATOM),
            'lastLoginAt' => new \DateTimeImmutable(self::LAST_LOGIN)->format(\DATE_ATOM),
        ], $data['account']);

        self::assertIsArray($data['devices']);
        self::assertSame(['Salon', 'Cuisine'], array_column($data['devices'], 'label'));
        [$salon, $cuisine] = $data['devices'];
        self::assertIsArray($salon);
        self::assertIsArray($cuisine);
        self::assertSame(['label', 'active', 'createdAt', 'updatedAt', 'displayConfigurations'], array_keys($salon));
        self::assertTrue($salon['active']);
        self::assertSame($device->getCreatedAt()->format(\DATE_ATOM), $salon['createdAt']);
        self::assertSame([], $cuisine['displayConfigurations']);

        self::assertIsArray($salon['displayConfigurations']);
        self::assertSame(['Vers la gare', 'Vers le port'], array_column($salon['displayConfigurations'], 'label'));
        $configuration = $salon['displayConfigurations'][0];
        self::assertIsArray($configuration);
        self::assertSame(['quay', 'label', 'position', 'active', 'createdAt', 'updatedAt'], array_keys($configuration));
        self::assertSame(['name' => 'CENTRE VILLE', 'gtfsStopId' => 'S1'], $configuration['quay']);
        self::assertSame(0, $configuration['position']);
        self::assertFalse($configuration['active']);
    }

    public function testTheExportLeavesOutSecretsInternalIdsAndOtherAccounts(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->user]);
        $this->configure($device, $this->quay('S1', 'CENTRE VILLE'), 'Vers la gare');
        $other = DeviceFactory::createOne(['label' => 'Écran de Noé']);

        $this->download();
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString($device->getToken(), $content);
        self::assertStringNotContainsString($other->getToken(), $content);
        self::assertStringNotContainsString('Écran de Noé', $content);
        self::assertStringNotContainsString((string) $this->user->getPassword(), $content);
        self::assertStringNotContainsString('"id"', $content);
        self::assertStringNotContainsString('"token"', $content);
        self::assertStringNotContainsString('"password"', $content);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function forgedPosts(): iterable
    {
        yield 'sans jeton' => [[]];
        yield 'avec un jeton invalide' => [['_token' => 'invalide']];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('forgedPosts')]
    public function testExportWithoutAValidCsrfTokenIsRefused(array $parameters): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/account/export', $parameters);

        // Un jeton invalide est une erreur d'authentification : retour à la connexion.
        self::assertResponseRedirects('/');
        self::assertResponseNotHasHeader('Content-Disposition');
    }

    public function testAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $this->client->request('POST', '/account/export');

        self::assertResponseRedirects('/');
        self::assertResponseNotHasHeader('Content-Disposition');
    }

    public function testExportIsOnlyReachableByPost(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/account/export');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * Téléchargement depuis le bouton de « Mon compte », à une heure figée.
     *
     * @return array<mixed>
     */
    private function download(): array
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->selectButton('Télécharger mes données')->form();
        self::assertSame('false', $form->getFormNode()->getAttribute('data-turbo'));

        // Noyau neuf pour remplacer l'horloge, puis sans reboot pour qu'elle reste en place.
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();

        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW, date_default_timezone_get()));

        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function configure(Device $device, Quay $quay, string $label, bool $active = true): void
    {
        $entityManager = $this->entityManager();
        $device = $entityManager->find(Device::class, $device->getId());
        self::assertInstanceOf(Device::class, $device);

        $configuration = new DisplayConfiguration($device, $quay)->setLabel($label)->setActive($active);
        $device->appendDisplayConfiguration($configuration);
        $entityManager->persist($configuration);
        $entityManager->flush();
    }

    private function quay(string $stopId, string $name): Quay
    {
        $quay = new Quay($stopId, $name, 38.5, -28.6, 'P'.$stopId);
        $entityManager = $this->entityManager();
        $entityManager->persist($quay);
        $entityManager->flush();

        return $quay;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
