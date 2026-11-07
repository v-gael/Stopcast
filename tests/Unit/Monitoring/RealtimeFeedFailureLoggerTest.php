<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Monitoring\RealtimeFeedFailureLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class RealtimeFeedFailureLoggerTest extends TestCase
{
    private TestHandler $logs;

    private MockClock $clock;

    private RealtimeFeedFailureLogger $failureLogger;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->clock = new MockClock('2026-11-07 08:00:00');
        $this->failureLogger = new RealtimeFeedFailureLogger(new Logger('app', [$this->logs]), new ArrayAdapter(), $this->clock);
    }

    public function testEveryFailureIsAWarningWithItsCause(): void
    {
        $this->failureLogger->log(InvalidGtfsRealtimeFeedException::stale('https://gtfs-rt.test/trip-updates', 420, 300));

        self::assertTrue($this->logs->hasWarningRecords());
        $warning = $this->logs->getRecords()[0];
        self::assertSame(Level::Warning, $warning->level);
        self::assertSame('GTFS-RT feed unavailable.', $warning->message);
        self::assertSame([
            'cause' => 'Flux GTFS-RT périmé : "https://gtfs-rt.test/trip-updates" (âge 420 s, maximum 300 s).',
            'exception_class' => InvalidGtfsRealtimeFeedException::class,
        ], $warning->context);
    }

    public function testCloseFailuresRaiseOneErrorThenAnotherAfterFifteenMinutes(): void
    {
        $failure = InvalidGtfsRealtimeFeedException::missingHeader('https://gtfs-rt.test/trip-updates');

        $this->failureLogger->log($failure);
        $this->clock->sleep(60);
        $this->failureLogger->log($failure);

        self::assertCount(2, $this->recordsAt(Level::Warning));
        self::assertCount(1, $this->recordsAt(Level::Error));

        $this->clock->sleep(14 * 60 - 1);
        $this->failureLogger->log($failure);

        self::assertCount(1, $this->recordsAt(Level::Error));

        $this->clock->sleep(1);
        $this->failureLogger->log($failure);

        self::assertCount(4, $this->recordsAt(Level::Warning));
        self::assertCount(2, $this->recordsAt(Level::Error));
        self::assertSame('GTFS-RT feed unavailable.', $this->recordsAt(Level::Error)[1]->message);
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private function recordsAt(Level $level): array
    {
        return array_values(array_filter($this->logs->getRecords(), static fn (\Monolog\LogRecord $record): bool => $record->level === $level));
    }
}
