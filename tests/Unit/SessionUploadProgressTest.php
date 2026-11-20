<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SessionUploadProgressTest extends TestCase
{
    public function testSessionUploadProgressIsOff(): void
    {
        self::assertFalse(
            filter_var(\ini_get('session.upload_progress.enabled'), \FILTER_VALIDATE_BOOL),
            "session.upload_progress.enabled doit valoir Off : frankenphp/conf.d/10-session.ini dans l'image, ini-values de setup-php dans la CI.",
        );
    }
}
