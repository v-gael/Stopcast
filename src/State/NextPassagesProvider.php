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
use App\Repository\DeviceRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @implements ProviderInterface<NextPassagesResponse>
 */
final readonly class NextPassagesProvider implements ProviderInterface
{
    public function __construct(
        private DeviceRepository $deviceRepository,
        private NextPassageFinder $nextPassageFinder,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NextPassagesResponse
    {
        /** @var Request|null $request */
        $request = $context['request'] ?? null;
        $token = $request?->headers->get('X-Device-Token');

        if (null === $token) {
            // Pas de UnauthorizedHttpException ici : elle force un header
            // WWW-Authenticate: Bearer, trompeur puisqu'on utilise un header
            // custom (X-Device-Token), pas un schéma Bearer standard.
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Token manquant.');
        }

        $device = $this->deviceRepository->findOneActiveByToken($token);

        if (!$device instanceof \App\Entity\Device) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Token invalide ou dispositif désactivé.');
        }

        $configurations = array_values(
            $device->getDisplayConfigurations()
                ->filter(static fn (DisplayConfiguration $configuration): bool => $configuration->isActive())
                ->toArray(),
        );

        usort(
            $configurations,
            static fn (DisplayConfiguration $a, DisplayConfiguration $b): int => $a->getPosition() <=> $b->getPosition(),
        );

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
