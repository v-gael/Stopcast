<?php

declare(strict_types=1);

namespace App\Security;

use App\Display\Pairing\DisplayCookies;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Lit le secret de l'écran dans son cookie : un navigateur en kiosque ne sait
 * pas envoyer d'en-tête, et un secret dans l'URL fuiterait dans les logs.
 */
final readonly class ScreenCookieExtractor implements AccessTokenExtractorInterface
{
    public function __construct(
        private DisplayCookies $displayCookies,
    ) {
    }

    public function extractAccessToken(Request $request): ?string
    {
        return $this->displayCookies->screenSecret($request);
    }
}
