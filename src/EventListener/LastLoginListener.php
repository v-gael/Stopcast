<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Date la connexion d'un compte, par le formulaire ou par « se souvenir de
 * moi » : la purge des comptes inactifs part de cette date. Les dispositifs
 * s'authentifient sur un autre firewall, dont l'activité ne compte pas.
 */
#[AsEventListener(dispatcher: 'security.event_dispatcher.main')]
final readonly class LastLoginListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin($this->clock->now());
        $this->entityManager->flush();
    }
}
