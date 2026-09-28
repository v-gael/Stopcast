<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gtfs\Static;

use App\Gtfs\Static\GtfsImportSummary;
use PHPUnit\Framework\TestCase;

final class GtfsImportSummaryTest extends TestCase
{
    public function testMergeSumsAllCounters(): void
    {
        $first = new GtfsImportSummary(created: 2, updated: 1, deactivated: 0);
        $second = new GtfsImportSummary(created: 3, updated: 4, deactivated: 5);

        $merged = $first->merge($second);

        self::assertSame(5, $merged->created);
        self::assertSame(5, $merged->updated);
        self::assertSame(5, $merged->deactivated);
    }

    public function testMergeDoesNotMutateOriginalInstances(): void
    {
        $first = new GtfsImportSummary(created: 1);
        $second = new GtfsImportSummary(created: 1);

        $first->merge($second);

        self::assertSame(1, $first->created);
        self::assertSame(1, $second->created);
    }

    public function testToStringFormatsCounters(): void
    {
        $summary = new GtfsImportSummary(created: 3, updated: 2, deactivated: 1);

        self::assertSame('3 créé(s), 2 mis à jour, 1 désactivé(s)', (string) $summary);
    }
}
