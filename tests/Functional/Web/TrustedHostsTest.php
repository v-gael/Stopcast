<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Hôtes acceptés dans l'en-tête Host (TRUSTED_HOSTS) : les liens des emails
 * sont construits à partir de lui.
 */
final class TrustedHostsTest extends WebTestCase
{
    public function testTrustedHostIsServed(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testUntrustedHostIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', '/', server: ['HTTP_HOST' => 'piege.example']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testHostContainingATrustedOneIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', '/', server: ['HTTP_HOST' => 'localhost.piege.example']);

        self::assertResponseStatusCodeSame(400);
    }
}
