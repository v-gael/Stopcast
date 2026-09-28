<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authentifie l'administrateur de l'API par sa clé (Authorization: Bearer <clé>).
 *
 * Une seule clé, définie par ADMIN_API_KEY. Si elle est vide ou absente, aucune
 * requête n'est authentifiée : l'administration est fermée plutôt qu'ouverte.
 */
final readonly class AdminApiKeyHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[Autowire(env: 'default::ADMIN_API_KEY')]
        #[\SensitiveParameter]
        private ?string $adminApiKey,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if (null === $this->adminApiKey || '' === $this->adminApiKey || !hash_equals($this->adminApiKey, $accessToken)) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        // Utilisateur déclaré dans le provider "admin" de security.yaml.
        return new UserBadge('admin');
    }
}
