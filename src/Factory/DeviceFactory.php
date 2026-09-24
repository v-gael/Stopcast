<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Device;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Device>
 */
final class DeviceFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Device::class;
    }

    protected function defaults(): array
    {
        return [
            'label' => \sprintf('Écran %s', self::faker()->streetName()),
            'active' => true,
        ];
    }
}
