<?php

declare(strict_types=1);

namespace App\Tests\Integration\Logging;

use App\Logging\PersonalDataMasker;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PersonalDataMaskingTest extends KernelTestCase
{
    public function testApplicationLoggerMasksPersonalData(): void
    {
        $logger = self::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->error('Login failed for jeanne@example.com from 203.0.113.42');

        self::assertTrue($handler->hasErrorThatContains('Login failed for j***@e***.com from 203.0.113.0'));
        self::assertFalse($handler->hasErrorThatContains('jeanne@example.com'));
    }

    // Les règles du masqueur et du journal d'accès de Caddy recopient le chemin
    // de la route : un renommage doit faire échouer ce test, pas le masquage.
    public function testResetTokenRulesFollowTheResetRoute(): void
    {
        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('app_reset_password', ['token' => 'Xk3vQ9pLmN2rT7wY']);
        $prefix = substr($path, 0, -\strlen('Xk3vQ9pLmN2rT7wY'));

        self::assertSame($prefix.'***', new PersonalDataMasker()->mask($path));

        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3).'/frankenphp/Caddyfile');
        self::assertStringContainsString(\sprintf('request>uri regexp "^%s[^/?]+" "%s***"', $prefix, $prefix), $caddyfile);
    }
}
