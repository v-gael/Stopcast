<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AdminApiKeyHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class AdminApiKeyHandlerTest extends TestCase
{
    public function testMatchingKeyAuthenticatesTheAdmin(): void
    {
        $badge = new AdminApiKeyHandler('secret-key')->getUserBadgeFrom('secret-key');

        self::assertSame('admin', $badge->getUserIdentifier());
    }

    public function testWrongKeyIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        new AdminApiKeyHandler('secret-key')->getUserBadgeFrom('other-key');
    }

    #[DataProvider('unconfiguredKeys')]
    public function testUnconfiguredKeyRejectsEveryToken(?string $configuredKey): void
    {
        $this->expectException(BadCredentialsException::class);

        new AdminApiKeyHandler($configuredKey)->getUserBadgeFrom('');
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unconfiguredKeys(): iterable
    {
        yield 'variable absente' => [null];
        yield 'variable vide' => [''];
    }
}
