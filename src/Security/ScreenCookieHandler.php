<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authentifie l'écran appairé d'un dispositif par le secret de son cookie,
 * cherché sous son haché.
 *
 * Un dispositif désactivé est authentifié quand même : son écran garde son
 * cookie et affiche « Écran désactivé » jusqu'à sa réactivation.
 */
final readonly class ScreenCookieHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private DeviceRepository $deviceRepository,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $device = $this->deviceRepository->findOneByScreenSecretHash(Device::hashScreenSecret($accessToken));

        if (!$device instanceof Device) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $user = new DeviceUser($device);

        // Le badge porte son propre chargeur : le secret ne sert pas d'identifiant.
        return new UserBadge($user->getUserIdentifier(), static fn (): DeviceUser => $user);
    }
}
