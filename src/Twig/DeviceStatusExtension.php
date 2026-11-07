<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Device;
use App\Enum\DeviceStatus;
use Symfony\Component\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * État d'un dispositif signalé par un badge dans l'espace web, à l'heure de
 * l'horloge de l'application.
 */
final readonly class DeviceStatusExtension
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    #[AsTwigFunction('device_status')]
    public function deviceStatus(Device $device): ?DeviceStatus
    {
        return $device->status($this->clock->now());
    }
}
