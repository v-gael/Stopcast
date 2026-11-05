<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\PersonalDataMasker;
use App\Logging\PersonalDataMaskingProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class PersonalDataMaskingProcessorTest extends TestCase
{
    private TestHandler $handler;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('app', [$this->handler], [new PersonalDataMaskingProcessor(new PersonalDataMasker())]);
    }

    public function testMessageContextAndExtraAreMasked(): void
    {
        $this->logger->pushProcessor(static fn ($record) => $record->with(extra: ['client_ip' => '203.0.113.42']));

        $this->logger->warning('Reset requested for jeanne@example.com', [
            'user' => ['email' => 'jeanne@example.com', 'ips' => ['2001:db8:1234:5678::1']],
        ]);

        $record = $this->handler->getRecords()[0];
        self::assertSame('Reset requested for j***@e***.com', $record->message);
        self::assertSame(['user' => ['email' => 'j***@e***.com', 'ips' => ['2001:db8:1234::']]], $record->context);
        self::assertSame(['client_ip' => '203.0.113.0'], $record->extra);
    }

    public function testNonStringValuesAndExceptionAreKept(): void
    {
        $exception = new \RuntimeException('Duplicate key jeanne@example.com');

        $this->logger->error('Import failed', ['exception' => $exception, 'count' => 3, 'done' => false, 'ratio' => null]);

        $context = $this->handler->getRecords()[0]->context;
        self::assertSame($exception, $context['exception']);
        self::assertSame(['count' => 3, 'done' => false, 'ratio' => null], array_diff_key($context, ['exception' => true]));
    }
}
