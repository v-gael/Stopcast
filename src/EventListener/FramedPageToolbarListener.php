<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * En dev, pas de barre de debug dans une page faite pour être affichée dans un
 * cadre (option de route « _frameable », voir SameOriginFramingListener) : ses
 * liens y ouvriraient le profiler, qui refuse d'être mis dans un cadre. La
 * requête reste profilée, et son profil se retrouve dans /_profiler.
 *
 * Retirer l'en-tête X-Debug-Token suffit : la barre n'est injectée que dans une
 * réponse qui le porte. Priorité -1536 : après le ProfilerListener (-1024), qui
 * le pose, et avant le WebDebugToolbarListener (-2048), qui injecte la barre.
 */
#[AsEventListener(priority: -1536)]
final readonly class FramedPageToolbarListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(SameOriginFramingListener::ATTRIBUTE)) {
            return;
        }

        $event->getResponse()->headers->remove('X-Debug-Token');
    }
}
