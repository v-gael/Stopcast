<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait AppLogsTrait
{
    /**
     * Journal du canal app pendant la suite du test (jusqu'au prochain
     * redémarrage du noyau).
     */
    private function appLogs(): TestHandler
    {
        $logger = self::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        return $handler;
    }
}
