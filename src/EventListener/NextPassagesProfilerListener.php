<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Pas de profil pour les appels de StopcastDisplay aux prochains passages
 * (un toutes les 10 secondes), sauf avec `?_profile=1` pour en examiner un.
 *
 * Priorité 31 : juste après le RouterListener (32), qui renseigne la route,
 * et avant le firewall (8), dont un refus arrête la propagation.
 */
#[AsEventListener(priority: 31)]
final readonly class NextPassagesProfilerListener
{
    private const string ROUTE = '_api_/next-passages_get';

    public function __construct(
        // Absent en prod : le listener ne fait alors rien.
        #[Autowire(service: 'profiler')]
        private ?Profiler $profiler,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->profiler instanceof Profiler || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::ROUTE === $request->attributes->get('_route') && '1' !== $request->query->get('_profile')) {
            $this->profiler->disable();
        }
    }
}
