<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NextPassagesResponse;
use App\Dto\GtfsRt\DisplayItem;
use App\Entity\DisplayConfiguration;
use App\Repository\DeviceRepository;
use App\Service\GtfsRealtimeFetcher;
use App\Service\NextPassageResolver;
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
        private GtfsRealtimeFetcher $fetcher,
        private NextPassageResolver $resolver,
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

        // Un seul appel réseau/parsing pour tous les quais de ce device : le flux
        // est déjà en mémoire (ou vient du cache partagé) et est réutilisé ci-dessous.
        // Si le flux RT échoue (timeout, protobuf invalide...), l'exception remonte
        // telle quelle : le Pi ne récupère rien cette fois-ci et garde son data.json
        // précédent, plutôt que d'écraser avec une réponse partielle/vide.
        $feed = $this->fetcher->fetchTripUpdates();

        $displays = [];

        foreach ($configurations as $configuration) {
            $displays[] = new DisplayItem(
                label: $configuration->getLabel(),
                quayName: $configuration->getQuay()->getName(),
                nextPassages: $this->resolver->resolveForQuay($feed, $configuration->getQuay()),
            );
        }

        return new NextPassagesResponse(
            generatedAt: new \DateTimeImmutable(),
            displays: $displays,
        );
    }
}
