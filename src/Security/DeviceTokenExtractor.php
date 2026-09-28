<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Lit le jeton du dispositif dans le header X-Device-Token, tel quel.
 *
 * Pas de HeaderAccessTokenExtractor : il n'accepte qu'un jeu de caractères
 * restreint, alors qu'un jeton imposé (fixtures) peut en contenir d'autres.
 */
final readonly class DeviceTokenExtractor implements AccessTokenExtractorInterface
{
    public function extractAccessToken(Request $request): ?string
    {
        $token = $request->headers->get('X-Device-Token');

        return null === $token || '' === $token ? null : $token;
    }
}
