<?php

declare(strict_types=1);

namespace App\Tests\Integration\Logging;

use App\Logging\PersonalDataMasker;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function tokenRouteProvider(): iterable
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (\in_array('token', $route->compile()->getPathVariables(), true)) {
                yield $name => [$name];
            }
        }

        self::ensureKernelShutdown();
    }

    // Toute route à jeton est masquée par le masqueur et par le journal
    // d'accès de Caddy, qui recopient son chemin : une nouvelle route ou un
    // renommage fait échouer ce test.
    #[DataProvider('tokenRouteProvider')]
    public function testTokenRouteIsMaskedByTheMaskerAndTheAccessLog(string $routeName): void
    {
        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate($routeName, ['token' => 'Xk3vQ9pLmN2rT7wY']);
        $prefix = substr($path, 0, -\strlen('Xk3vQ9pLmN2rT7wY'));

        self::assertSame($prefix.'***', new PersonalDataMasker()->mask($path));

        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3).'/frankenphp/Caddyfile');
        self::assertStringContainsString(\sprintf('request>uri regexp "^%s[^/?]+" "%s***"', $prefix, $prefix), $caddyfile);
    }
}
