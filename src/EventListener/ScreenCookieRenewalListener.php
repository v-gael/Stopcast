<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Display\Pairing\DisplayCookies;
use App\Security\DeviceUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Renvoie le cookie de l'écran, même secret et 400 jours de plus, sur chaque
 * réponse à un écran authentifié par lui : un écran qui tourne n'expire
 * jamais, sans date d'émission à garder en base.
 */
#[AsEventListener]
final readonly class ScreenCookieRenewalListener
{
    public function __construct(
        private Security $security,
        private DisplayCookies $displayCookies,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$event->isMainRequest()
            || 'display' !== $this->security->getFirewallConfig($request)?->getName()
            || !$this->security->getUser() instanceof DeviceUser
            || $this->displayCookies->hasScreenCookie($response)) {
            return;
        }

        $secret = $this->displayCookies->screenSecret($request);

        if (null !== $secret) {
            $this->displayCookies->setScreen($response, $secret, $this->clock->now());
        }
    }
}
