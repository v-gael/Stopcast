<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TimezoneTest extends TestCase
{
    public function testPhpRunsInTheNetworkTimezone(): void
    {
        self::assertSame(
            'Europe/Paris',
            date_default_timezone_get(),
            "date.timezone doit valoir Europe/Paris : frankenphp/conf.d/10-timezone.ini dans l'image, ini-values de setup-php dans la CI.",
        );
    }
}
