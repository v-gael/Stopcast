<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;

final class SecurityTxtTest extends TestCase
{
    private const string PATH = __DIR__.'/../../../public/.well-known/security.txt';

    public function testSecurityTxtHasAContact(): void
    {
        self::assertStringStartsWith('https://', $this->field('Contact'));
    }

    public function testSecurityTxtExpiresInMoreThanThirtyDays(): void
    {
        $expires = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $this->field('Expires'));
        self::assertNotFalse($expires, 'Expires doit être une date ISO 8601, par exemple 2027-10-30T00:00:00Z.');
        self::assertGreaterThan(
            new \DateTimeImmutable('+30 days'),
            $expires,
            \sprintf('security.txt expire le %s : reporter Expires à un an (public/.well-known/security.txt).', $expires->format('Y-m-d')),
        );
    }

    private function field(string $name): string
    {
        $content = file_get_contents(self::PATH);
        self::assertIsString($content);

        if (1 !== preg_match('/^'.$name.': (\S+)$/m', $content, $matches)) {
            self::fail(\sprintf('security.txt doit avoir un champ %s.', $name));
        }

        return $matches[1];
    }
}
