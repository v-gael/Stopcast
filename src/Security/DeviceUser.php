<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispositif d'affichage authentifié par son jeton (X-Device-Token).
 *
 * Enveloppe le Device plutôt que d'en faire un utilisateur Security : l'entité
 * reste libre de Security, et un écran ne se confond pas avec un utilisateur
 * humain.
 */
final readonly class DeviceUser implements UserInterface
{
    public function __construct(
        public Device $device,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_DEVICE'];
    }

    public function getUserIdentifier(): string
    {
        return \sprintf('device-%d', $this->device->getId());
    }
}
