<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\EmailNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'déjà normalisée' => ['gael@example.com', 'gael@example.com'];
        yield 'espaces autour' => ["  gael@example.com\t", 'gael@example.com'];
        yield 'majuscules, partie locale comprise' => ['Gael.V@Example.COM', 'gael.v@example.com'];
        yield 'caractères non ASCII' => ['ÉLODIE@Exemple.FR', 'élodie@exemple.fr'];
    }

    #[DataProvider('addresses')]
    public function testNormalizedAddressIsTrimmedAndLowercased(string $input, string $expected): void
    {
        self::assertSame($expected, EmailNormalizer::normalize($input));
    }
}
