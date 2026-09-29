<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminAccessTest extends WebTestCase
{
    use ResetDatabase;

    private const string ADMIN_API_KEY = 'test-admin-api-key';

    private KernelBrowser $client;

    private Device $device;

    private DisplayConfiguration $configuration;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $this->device = new Device('Écran test');

        $entityManager->persist(new Route('R1', '1', 'FF5500', 'FFFFFF'));
        $entityManager->persist($quay);
        $entityManager->persist($this->device);

        $this->configuration = new DisplayConfiguration($this->device, $quay);
        $this->device->addDisplayConfiguration($this->configuration);
        $entityManager->persist($this->configuration);
        $entityManager->flush();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function adminEndpoints(): iterable
    {
        yield 'liste des appareils' => ['GET', '/api/devices'];
        yield 'appareil' => ['GET', '/api/devices/{device}'];
        yield 'création d\'appareil' => ['POST', '/api/devices'];
        yield "modification d'appareil" => ['PATCH', '/api/devices/{device}'];
        yield "suppression d'appareil" => ['DELETE', '/api/devices/{device}'];
        yield 'liste des configurations' => ['GET', '/api/display_configurations'];
        yield 'configuration' => ['GET', '/api/display_configurations/{configuration}'];
        yield 'création de configuration' => ['POST', '/api/display_configurations'];
        yield 'modification de configuration' => ['PATCH', '/api/display_configurations/{configuration}'];
        yield 'suppression de configuration' => ['DELETE', '/api/display_configurations/{configuration}'];
        yield 'liste des quais' => ['GET', '/api/quays'];
        yield 'liste des lignes' => ['GET', '/api/routes'];
        yield 'documentation OpenAPI' => ['GET', '/api/docs.jsonld'];
        yield 'point d\'entrée' => ['GET', '/api'];
    }

    #[DataProvider('adminEndpoints')]
    public function testAdminEndpointRejectsRequestWithoutKey(string $method, string $path): void
    {
        $this->request($method, $path);

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('adminEndpoints')]
    public function testAdminEndpointRejectsWrongKey(string $method, string $path): void
    {
        $this->request($method, $path, ['HTTP_AUTHORIZATION' => 'Bearer wrong-key']);

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('adminEndpoints')]
    public function testAdminEndpointRejectsDeviceToken(string $method, string $path): void
    {
        $this->request($method, $path, ['HTTP_AUTHORIZATION' => 'Bearer '.$this->device->getToken()]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminReadEndpoints(): iterable
    {
        yield 'liste des quais' => ['/api/quays'];
        yield 'liste des lignes' => ['/api/routes'];
        yield 'liste des configurations' => ['/api/display_configurations'];
        yield 'documentation OpenAPI' => ['/api/docs.jsonld'];
    }

    #[DataProvider('adminReadEndpoints')]
    public function testAdminCanReadWithKey(string $path): void
    {
        $this->request('GET', $path, $this->adminHeaders());

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanListDevices(): void
    {
        $this->request('GET', '/api/devices', $this->adminHeaders());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Écran test', (string) $this->client->getResponse()->getContent());
    }

    public function testAdminCanCreateDevice(): void
    {
        $this->request('POST', '/api/devices', $this->adminHeaders(), ['label' => 'Nouvel écran']);

        self::assertResponseStatusCodeSame(201);
    }

    public function testTokenCannotBeChosenWhenCreatingADevice(): void
    {
        $this->request('POST', '/api/devices', $this->adminHeaders(), ['label' => 'Nouvel écran', 'token' => 'jeton-choisi']);

        self::assertResponseStatusCodeSame(201);
        $device = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($device);
        self::assertIsString($device['token'] ?? null);
        self::assertNotSame('jeton-choisi', $device['token']);
    }

    public function testAdminCanDeleteDisplayConfiguration(): void
    {
        $this->request('DELETE', '/api/display_configurations/{configuration}', $this->adminHeaders());

        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return iterable<string, array{class-string<Quay|Route>, string, string}>
     */
    public static function referenceCollections(): iterable
    {
        yield 'quais' => [Quay::class, '/api/quays', 'gtfsStopId'];
        yield 'lignes' => [Route::class, '/api/routes', 'gtfsRouteId'];
    }

    /**
     * @param class-string<Quay|Route> $class
     */
    #[DataProvider('referenceCollections')]
    public function testActiveFilterExcludesOnlyDeprecatedReferencesWithoutExposingActive(string $class, string $path, string $idField): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $deprecated = Quay::class === $class
            ? new Quay('S0', 'QUAI SUPPRIMÉ', 38.5, -28.6, 'P0')
            : new Route('R0', '0', '000000', 'FFFFFF');
        $entityManager->persist($deprecated->markDeprecated());
        $entityManager->flush();

        $activeId = Quay::class === $class ? 'S1' : 'R1';
        $deprecatedId = Quay::class === $class ? 'S0' : 'R0';

        self::assertEqualsCanonicalizing([$activeId, $deprecatedId], $this->collectionIds($path, $idField));
        self::assertSame([$activeId], $this->collectionIds($path.'?exists[deprecatedAt]=false', $idField));
    }

    /**
     * @return string[]
     */
    private function collectionIds(string $path, string $idField): array
    {
        $this->request('GET', $path, $this->adminHeaders());
        self::assertResponseIsSuccessful();

        $collection = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($collection);
        self::assertIsArray($collection['member'] ?? null);

        $ids = [];
        foreach ($collection['member'] as $member) {
            self::assertIsArray($member);
            self::assertArrayNotHasKey('active', $member);
            self::assertIsString($member[$idField] ?? null);
            $ids[] = $member[$idField];
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.self::ADMIN_API_KEY];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, mixed>  $body
     */
    private function request(string $method, string $path, array $server = [], array $body = []): void
    {
        $path = strtr($path, [
            '{device}' => (string) $this->device->getId(),
            '{configuration}' => (string) $this->configuration->getId(),
        ]);

        $contentType = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';

        $this->client->request($method, $path, server: $server + ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/ld+json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
