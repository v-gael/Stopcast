<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Enum\DeviceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    public function testNewDeviceHasNoPairedScreen(): void
    {
        self::assertFalse(new Device($this->owner(), 'A')->hasPairedScreen());
    }

    public function testPairingAScreenKeepsOnlyTheHashOfItsSecret(): void
    {
        $device = new Device($this->owner(), 'A')->pairScreen('secret-de-l-ecran');

        self::assertTrue($device->hasPairedScreen());
        // SHA-256 de « secret-de-l-ecran », calculé à part.
        self::assertSame('462e5b58629bac4eb9e97d66bfafe0b4337e875547a5d1f576e8b0879041e12d', $device->getScreenSecretHash());
    }

    public function testPairingAnotherScreenReplacesTheFirst(): void
    {
        $device = new Device($this->owner(), 'A')->pairScreen('premier')->pairScreen('second');

        self::assertSame(Device::hashScreenSecret('second'), $device->getScreenSecretHash());
    }

    public function testFirstContactIsRecorded(): void
    {
        $device = new Device($this->owner(), 'A');
        $now = new \DateTimeImmutable('2026-10-30 14:32:00');

        self::assertTrue($device->recordContact($now));
        self::assertEquals($now, $device->getLastSeenAt());
    }

    public function testContactWithinFiveMinutesIsIgnored(): void
    {
        $device = new Device($this->owner(), 'A');
        $first = new \DateTimeImmutable('2026-10-30 14:32:00');
        $device->recordContact($first);

        self::assertFalse($device->recordContact($first->modify('+4 minutes')));
        self::assertEquals($first, $device->getLastSeenAt());
    }

    public function testContactAfterFiveMinutesIsRecorded(): void
    {
        $device = new Device($this->owner(), 'A');
        $first = new \DateTimeImmutable('2026-10-30 14:32:00');
        $device->recordContact($first);

        self::assertTrue($device->recordContact($first->modify('+6 minutes')));
        self::assertEquals($first->modify('+6 minutes'), $device->getLastSeenAt());
    }

    public function testUnpairingOrPairingAgainKeepsTheLastContact(): void
    {
        $device = new Device($this->owner(), 'A')->pairScreen('premier');
        $now = new \DateTimeImmutable('2026-10-30 14:32:00');
        $device->recordContact($now);

        $device->unpairScreen()->pairScreen('second');

        self::assertEquals($now, $device->getLastSeenAt());
    }

    /**
     * @return iterable<string, array{?string, bool, bool, ?DeviceStatus}>
     */
    public static function statusCases(): iterable
    {
        yield 'vu il y a 2 min' => ['-2 minutes', true, true, null];
        yield 'vu il y a 15 min pile' => ['-15 minutes', true, true, null];
        yield 'vu il y a 20 min' => ['-20 minutes', true, true, DeviceStatus::Offline];
        yield 'jamais vu' => [null, true, true, DeviceStatus::Offline];
        yield 'sans écran' => ['-20 minutes', false, true, DeviceStatus::Unpaired];
        yield 'désactivé et silencieux' => ['-20 minutes', true, false, null];
        yield 'désactivé sans écran' => [null, false, false, null];
    }

    #[DataProvider('statusCases')]
    public function testStatusFlagsAMissingOrSilentScreenOfAnActiveDevice(?string $lastSeen, bool $paired, bool $active, ?DeviceStatus $status): void
    {
        $now = new \DateTimeImmutable('2026-10-30 14:32:00');
        $device = new Device($this->owner(), 'A')->setActive($active);
        if ($paired) {
            $device->pairScreen('secret');
        }

        if (null !== $lastSeen) {
            $device->recordContact($now->modify($lastSeen));
        }

        self::assertSame($status, $device->status($now));
    }

    public function testUnpairingForgetsTheScreen(): void
    {
        $device = new Device($this->owner(), 'A')->pairScreen('secret')->unpairScreen();

        self::assertFalse($device->hasPairedScreen());
        self::assertNull($device->getScreenSecretHash());
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
