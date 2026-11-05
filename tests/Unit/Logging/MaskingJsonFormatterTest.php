<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\MaskingJsonFormatter;
use App\Logging\PersonalDataMasker;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class MaskingJsonFormatterTest extends TestCase
{
    public function testExceptionMessageIsMaskedInTheJsonLine(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'messenger', Level::Critical, 'Message rejected', [
            'exception' => new \RuntimeException('Mailbox jeanne@example.com refused from 203.0.113.42'),
        ]);

        $line = new MaskingJsonFormatter(new PersonalDataMasker())->format($record);

        self::assertStringNotContainsString('jeanne@example.com', $line);
        self::assertStringNotContainsString('203.0.113.42', $line);
        self::assertStringContainsString('Mailbox j***@e***.com refused from 203.0.113.0', $line);
        self::assertIsArray(json_decode($line, true, flags: \JSON_THROW_ON_ERROR));
    }
}
