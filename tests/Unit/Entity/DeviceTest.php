<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    public function testTokenIsGeneratedWhenNoneIsGiven(): void
    {
        self::assertNotSame(new Device('A')->getToken(), new Device('B')->getToken());
    }

    public function testGivenTokenIsKept(): void
    {
        self::assertSame('jeton-fixe', new Device('A', 'jeton-fixe')->getToken());
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsRejected(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Device('A', $token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'vide' => [''];
        yield 'plus de 64 caractères' => [str_repeat('a', Device::TOKEN_MAX_LENGTH + 1)];
    }
}
