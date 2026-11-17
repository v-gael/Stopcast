<?php

declare(strict_types=1);

namespace App\Twig;

use App\Display\Pairing\ScreenPairing;
use App\Entity\Device;
use App\Enum\DeviceStatus;
use Symfony\Component\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * État résumé d'un dispositif dans l'espace web, à l'heure de l'horloge de
 * l'application.
 */
final readonly class DeviceStatusExtension
{
    public function __construct(
        private ClockInterface $clock,
        private ScreenPairing $screenPairing,
    ) {
    }

    /**
     * L'état du dispositif, ou « Appairage en cours » si un code vient d'être
     * saisi pour un dispositif actif.
     */
    #[AsTwigFunction('device_status')]
    public function deviceStatus(Device $device): DeviceStatus
    {
        $status = $device->status($this->clock->now());

        return DeviceStatus::Disabled !== $status && $this->screenPairing->isAwaitingScreen($device) ? DeviceStatus::Pairing : $status;
    }

    /**
     * L'état va changer dans les secondes qui viennent : un code vient d'être
     * saisi, ou l'écran tout juste appairé ne s'est pas encore connecté.
     */
    #[AsTwigFunction('device_status_settling')]
    public function deviceStatusSettling(Device $device): bool
    {
        return match ($this->deviceStatus($device)) {
            DeviceStatus::Pairing => true,
            DeviceStatus::NeverSeen => $this->screenPairing->isAwaitingFirstContact($device),
            default => false,
        };
    }
}
