<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NextPassagesResponse;
use App\Display\DisplayAssembler;
use App\Security\DeviceUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * Écrans du dispositif authentifié par le firewall "device", assemblés par
 * DisplayAssembler.
 *
 * @implements ProviderInterface<NextPassagesResponse>
 */
final readonly class NextPassagesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private DisplayAssembler $displayAssembler,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NextPassagesResponse
    {
        $user = $this->security->getUser();

        // access_control exige ROLE_DEVICE sur cette route : seul un DeviceUser arrive ici.
        if (!$user instanceof DeviceUser) {
            throw new \LogicException(\sprintf('Un %s est attendu, %s reçu.', DeviceUser::class, get_debug_type($user)));
        }

        // Si le flux RT échoue (timeout, protobuf invalide...), l'exception remonte
        // telle quelle : le Pi ne récupère rien cette fois-ci et garde son data.json
        // précédent, plutôt que d'écraser avec une réponse partielle/vide.
        return new NextPassagesResponse(
            generatedAt: $this->clock->now(),
            displays: $this->displayAssembler->forDevice($user->device),
        );
    }
}
