<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use App\Security\DeviceTokenHandler;
use App\Security\DeviceUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class DeviceTokenHandlerTest extends TestCase
{
    public function testActiveDeviceTokenAuthenticatesTheDevice(): void
    {
        $device = new Device('Écran', 'jeton-actif');
        $repository = $this->createStub(DeviceRepository::class);
        $repository->method('findOneActiveByToken')->willReturnMap([['jeton-actif', $device]]);

        $user = new DeviceTokenHandler($repository)->getUserBadgeFrom('jeton-actif')->getUser();

        self::assertInstanceOf(DeviceUser::class, $user);
        self::assertSame($device, $user->device);
        self::assertSame(['ROLE_DEVICE'], $user->getRoles());
    }

    public function testTokenIsNotUsedAsUserIdentifier(): void
    {
        $repository = $this->createStub(DeviceRepository::class);
        $repository->method('findOneActiveByToken')->willReturn(new Device('Écran', 'jeton-actif'));

        $badge = new DeviceTokenHandler($repository)->getUserBadgeFrom('jeton-actif');

        self::assertStringNotContainsString('jeton-actif', $badge->getUserIdentifier());
    }

    public function testUnknownOrDisabledDeviceTokenIsRejected(): void
    {
        $repository = $this->createStub(DeviceRepository::class);
        $repository->method('findOneActiveByToken')->willReturn(null);

        $this->expectException(BadCredentialsException::class);

        new DeviceTokenHandler($repository)->getUserBadgeFrom('jeton-inconnu');
    }
}
