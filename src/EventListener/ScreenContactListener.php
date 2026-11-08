<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\DeviceUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Date le dernier contact de l'écran d'un dispositif. Le firewall de la page
 * d'affichage est stateless : il authentifie l'écran par son cookie à chaque
 * requête, avant le contrôleur, y compris un rafraîchissement qui échouera
 * faute de flux temps réel. Un écran en attente d'appairage n'est pas
 * authentifié et ne compte pas.
 */
#[AsEventListener(dispatcher: 'security.event_dispatcher.display')]
final readonly class ScreenContactListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof DeviceUser) {
            return;
        }

        if ($user->device->recordContact($this->clock->now())) {
            $this->entityManager->flush();
        }
    }
}
