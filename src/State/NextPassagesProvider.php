<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NextPassagesResponse;
use App\Dto\GtfsRt\DisplayItem;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Gtfs\Realtime\NextPassageFinder;
use App\Security\DeviceUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * Assemble les écrans du dispositif authentifié par le firewall "device".
 *
 * @implements ProviderInterface<NextPassagesResponse>
 */
final readonly class NextPassagesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private NextPassageFinder $nextPassageFinder,
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

        $configurations = $user->device->getActiveDisplayConfigurations();

        // Un seul appel pour tous les quais de ce device : le flux n'est lu qu'une fois.
        // Si le flux RT échoue (timeout, protobuf invalide...), l'exception remonte
        // telle quelle : le Pi ne récupère rien cette fois-ci et garde son data.json
        // précédent, plutôt que d'écraser avec une réponse partielle/vide.
        $passagesByStopId = $this->nextPassageFinder->forQuays(
            array_map(static fn (DisplayConfiguration $configuration): Quay => $configuration->getQuay(), $configurations),
        );

        $displays = [];

        foreach ($configurations as $configuration) {
            $displays[] = new DisplayItem(
                label: $configuration->getLabel(),
                quayName: $configuration->getQuay()->getName(),
                nextPassages: $passagesByStopId[$configuration->getQuay()->getGtfsStopId()],
            );
        }

        return new NextPassagesResponse(
            generatedAt: $this->clock->now(),
            displays: $displays,
        );
    }
}
