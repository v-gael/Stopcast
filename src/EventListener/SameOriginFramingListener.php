<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Le site interdit d'être affiché dans un cadre (nelmio_security.yaml). Une
 * route qui porte l'option « _frameable » à true, comme l'aperçu de la page
 * d'affichage, peut l'être par une page du site lui-même : X-Frame-Options et
 * frame-ancestors passent alors à la même origine.
 *
 * Priorité -16 : après les listeners de NelmioSecurityBundle (0), qui posent
 * ces en-têtes.
 */
#[AsEventListener(priority: -16)]
final readonly class SameOriginFramingListener
{
    public const string ATTRIBUTE = '_frameable';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(self::ATTRIBUTE)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN');

        $policy = $headers->get('Content-Security-Policy');

        if (null !== $policy) {
            $headers->set('Content-Security-Policy', preg_replace('/frame-ancestors [^;]*/', "frame-ancestors 'self'", $policy));
        }
    }
}
