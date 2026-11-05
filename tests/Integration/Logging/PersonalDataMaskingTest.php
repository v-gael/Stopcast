<?php

declare(strict_types=1);

namespace App\Tests\Integration\Logging;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
}
