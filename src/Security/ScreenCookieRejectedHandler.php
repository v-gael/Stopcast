<?php

declare(strict_types=1);

namespace App\Security;

use App\Display\Pairing\DisplayCookies;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Cookie d'écran que plus aucun dispositif ne reconnaît (écran dissocié,
 * remplacé, dispositif supprimé) : le cookie est effacé, puis la page se
 * recharge sur l'écran d'appairage.
 *
 * Le chargement de la page est redirigé vers elle-même ; un rafraîchissement
 * ou une interrogation reçoit un 401, sur lequel le navigateur recharge la page.
 */
final readonly class ScreenCookieRejectedHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private DisplayCookies $displayCookies,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $response = '/display' === $request->getPathInfo() && !$request->query->has('fragment')
            ? new RedirectResponse($request->getRequestUri(), Response::HTTP_SEE_OTHER)
            : new Response(null, Response::HTTP_UNAUTHORIZED);

        $this->displayCookies->clearScreen($response);

        return $response;
    }
}
