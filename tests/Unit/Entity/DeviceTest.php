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

    public function testAppendedConfigurationGoesToTheEndOfTheRotation(): void
    {
        $device = new Device($this->owner(), 'A');
        $device->addDisplayConfiguration($this->configuration($device, 'Masqué')->setPosition(4)->setActive(false));
        $device->addDisplayConfiguration($this->configuration($device, 'Premier')->setPosition(1));

        $appended = $this->configuration($device, 'Nouveau');
        $device->appendDisplayConfiguration($appended);

        self::assertTrue($device->getDisplayConfigurations()->contains($appended));
        self::assertSame(5, $appended->getPosition());
    }

    public function testFirstAppendedConfigurationStartsTheRotation(): void
    {
        $device = new Device($this->owner(), 'A');
        $appended = $this->configuration($device, 'Seul');

        $device->appendDisplayConfiguration($appended);

        self::assertSame(0, $appended->getPosition());
    }

    public function testMovingUpSwapsWithThePreviousConfigurationMaskedOnesIncluded(): void
    {
        $device = $this->deviceWithRotation(['Premier' => 0, 'Masqué' => 3, 'Troisième' => 7]);

        $device->moveDisplayConfigurationUp($this->find($device, 'Troisième'));

        self::assertSame(['Premier' => 0, 'Troisième' => 1, 'Masqué' => 2], $this->rotation($device));
    }

    public function testMovingDownSwapsWithTheNextConfiguration(): void
    {
        $device = $this->deviceWithRotation(['Premier' => 0, 'Deuxième' => 1, 'Troisième' => 2]);

        $device->moveDisplayConfigurationDown($this->find($device, 'Premier'));

        self::assertSame(['Deuxième' => 0, 'Premier' => 1, 'Troisième' => 2], $this->rotation($device));
    }

    public function testMovingBeyondTheEndsChangesNothing(): void
    {
        $device = $this->deviceWithRotation(['Premier' => 0, 'Dernier' => 1]);

        $device->moveDisplayConfigurationUp($this->find($device, 'Premier'));
        $device->moveDisplayConfigurationDown($this->find($device, 'Dernier'));

        self::assertSame(['Premier' => 0, 'Dernier' => 1], $this->rotation($device));
    }

    public function testMovingSeparatesConfigurationsSharingAPosition(): void
    {
        $device = $this->deviceWithRotation(['Premier' => 0, 'Deuxième' => 0, 'Troisième' => 0]);

        $device->moveDisplayConfigurationUp($this->find($device, 'Troisième'));

        self::assertSame(['Premier' => 0, 'Troisième' => 1, 'Deuxième' => 2], $this->rotation($device));
    }

    /**
     * @param array<string, int> $positions
     */
    private function deviceWithRotation(array $positions): Device
    {
        $device = new Device($this->owner(), 'A');

        foreach ($positions as $label => $position) {
            $device->addDisplayConfiguration($this->configuration($device, $label)->setPosition($position)->setActive('Masqué' !== $label));
        }

        return $device;
    }

    private function find(Device $device, string $label): DisplayConfiguration
    {
        $configuration = $device->getDisplayConfigurations()->findFirst(static fn (int $key, DisplayConfiguration $configuration): bool => $label === $configuration->getLabel());
        self::assertInstanceOf(DisplayConfiguration::class, $configuration);

        return $configuration;
    }

    /**
     * @return array<string, int>
     */
    private function rotation(Device $device): array
    {
        $rotation = [];

        foreach ($device->getDisplayConfigurationsInRotationOrder() as $configuration) {
            $rotation[(string) $configuration->getLabel()] = $configuration->getPosition();
        }

        return $rotation;
    }

    private function configuration(Device $device, string $label): DisplayConfiguration
    {
        return new DisplayConfiguration($device, new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1'))->setLabel($label);
    }

    private function owner(): User
    {
        return new User('lea@example.com');
    }
}
