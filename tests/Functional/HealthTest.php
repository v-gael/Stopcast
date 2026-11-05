<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\AppLogsTrait;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /health : appelé par le healthcheck Docker, bin/smoke-test et la sonde
 * d'uptime, sans compte.
 */
final class HealthTest extends WebTestCase
{
    use AppLogsTrait;

    public function testAnswersOkWhenTheDatabaseResponds(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testAnswers503WithoutDetailWhenTheDatabaseIsUnreachable(): void
    {
        $client = self::createClient();
        $logs = $this->appLogs();
        // Une vraie connexion, vers un hôte qui n'existe pas (.invalid, RFC 2606).
        self::getContainer()->set('doctrine.dbal.default_connection', DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'database.invalid',
            'dbname' => 'app',
            'user' => 'app',
            'password' => 'secret-qui-ne-doit-pas-fuiter',
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 2],
        ]));

        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('{"status":"error"}', $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertTrue($logs->hasErrorThatPasses(
            static fn (LogRecord $record): bool => 'Health check: the database is unreachable.' === $record->message
                && ($record->context['exception'] ?? null) instanceof DbalException,
        ));
    }
}
