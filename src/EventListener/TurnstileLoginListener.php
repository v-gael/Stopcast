<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\TurnstileVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Captcha Turnstile du formulaire de connexion (app_login). Après la limite
 * de débit (LoginThrottlingListener, 2080) : au-delà de la limite, Cloudflare
 * n'est pas appelé, et un captcha raté compte comme un essai. Avant le
 * chargement du compte (UserProviderListener, 1024) et le mot de passe.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 1500, dispatcher: 'security.event_dispatcher.main')]
final readonly class TurnstileLoginListener
{
    public function __construct(
        private RequestStack $requestStack,
        private TurnstileVerifier $turnstileVerifier,
    ) {
    }

    public function __invoke(): void
    {
        $request = $this->requestStack->getMainRequest();
        if ('app_login' !== $request?->attributes->get('_route')) {
            return;
        }

        if (!$this->turnstileVerifier->verify($request->request->getString(TurnstileVerifier::RESPONSE_FIELD))) {
            throw new CustomUserMessageAuthenticationException(TurnstileVerifier::FAILURE_MESSAGE);
        }
    }
}
