<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authentifie un dispositif d'affichage par son jeton (X-Device-Token).
 *
 * Seul un dispositif actif est authentifié : le désactiver lui coupe l'accès.
 */
final readonly class DeviceTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private DeviceRepository $deviceRepository,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $device = $this->deviceRepository->findOneActiveByToken($accessToken);

        if (!$device instanceof Device) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $user = new DeviceUser($device);

        // Le badge porte son propre chargeur : le jeton ne sert pas d'identifiant.
        return new UserBadge($user->getUserIdentifier(), static fn (): DeviceUser => $user);
    }
}
