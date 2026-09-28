<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Refus d'un dispositif d'affichage : 401 au format d'erreur de l'API, sans
 * header WWW-Authenticate, trompeur puisque le jeton passe par un header custom
 * (X-Device-Token) et non par un schéma standard comme Bearer.
 *
 * Lever une HttpException plutôt que construire la réponse laisse API Platform
 * la rendre comme ses autres erreurs, ce que fait aussi Symfony pour un firewall
 * sans entry point. Pas d'exception précédente : une AuthenticationException
 * chaînée serait reprise par l'ExceptionListener de Security.
 */
final readonly class DeviceUnauthorizedHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    /**
     * Aucun jeton : l'authenticator ne s'est pas déclenché, l'accès est refusé par access_control.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Token manquant.');
    }

    /**
     * Jeton inconnu ou dispositif désactivé.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Token invalide ou dispositif désactivé.');
    }
}
