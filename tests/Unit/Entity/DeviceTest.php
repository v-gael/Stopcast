<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    public function testTokenIsGeneratedWhenNoneIsGiven(): void
    {
        self::assertNotSame(new Device($this->owner(), 'A')->getToken(), new Device($this->owner(), 'B')->getToken());
    }

    public function testGivenTokenIsKept(): void
    {
        self::assertSame('jeton-fixe', new Device($this->owner(), 'A', 'jeton-fixe')->getToken());
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsRejected(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Device($this->owner(), 'A', $token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'vide' => [''];
        yield 'plus de 64 caractères' => [str_repeat('a', Device::TOKEN_MAX_LENGTH + 1)];
    }

    public function testActiveDisplayConfigurationsAreSortedByPosition(): void
    {
        $device = new Device($this->owner(), 'A');
        $quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');

        foreach ([['Troisième', 5, true], ['Masqué', 0, false], ['Premier', 1, true], ['Deuxième', 2, true]] as [$label, $position, $active]) {
            $device->addDisplayConfiguration(new DisplayConfiguration($device, $quay)->setLabel($label)->setPosition($position)->setActive($active));
        }

        self::assertSame(
            ['Premier', 'Deuxième', 'Troisième'],
            array_map(static fn (DisplayConfiguration $configuration): ?string => $configuration->getLabel(), $device->getActiveDisplayConfigurations()),
        );
    }

    public function testDeviceWithoutActiveConfigurationHasNothingToDisplay(): void
    {
        $device = new Device($this->owner(), 'A');
        $device->addDisplayConfiguration(new DisplayConfiguration($device, new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1'))->setActive(false));

        self::assertSame([], $device->getActiveDisplayConfigurations());
    }

    private function owner(): User
    {
        return new User('lea@example.com');
    }
}
