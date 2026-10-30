<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'API ne sert plus que le dispositif d'affichage : l'administration passe
 * par l'espace web, et la documentation est publique.
 */
final class ApiSurfaceTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function removedEndpoints(): iterable
    {
        yield 'liste des appareils' => ['GET', '/api/devices'];
        yield 'appareil' => ['GET', '/api/devices/1'];
        yield "création d'appareil" => ['POST', '/api/devices'];
        yield 'liste des configurations' => ['GET', '/api/display_configurations'];
        yield 'configuration' => ['GET', '/api/display_configurations/1'];
        yield 'liste des quais' => ['GET', '/api/quays'];
        yield 'liste des lignes' => ['GET', '/api/routes'];
    }

    #[DataProvider('removedEndpoints')]
    public function testAdministrationEndpointsNoLongerExist(string $method, string $path): void
    {
        $client = self::createClient();

        $client->request($method, $path, server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentation(): iterable
    {
        yield "point d'entrée" => ['/api', 'text/html'];
        yield 'spécification OpenAPI' => ['/api/docs.jsonopenapi', 'application/vnd.openapi+json'];
    }

    #[DataProvider('documentation')]
    public function testDocumentationIsPublic(string $path, string $accept): void
    {
        $client = self::createClient();

        $client->request('GET', $path, server: ['HTTP_ACCEPT' => $accept]);

        self::assertResponseIsSuccessful();
    }

    public function testDocumentationOnlyDescribesTheDeviceEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful();
        $specification = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($specification);
        self::assertIsArray($specification['paths'] ?? null);
        self::assertSame(['/api/next-passages'], array_keys($specification['paths']));
        self::assertIsArray($specification['info'] ?? null);
        self::assertSame('Stopcast API', $specification['info']['title'] ?? null);
    }
}
