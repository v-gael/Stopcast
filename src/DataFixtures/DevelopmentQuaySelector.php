<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Quay;
use App\Exception\InvalidGtfsRealtimeFeedException;
use App\Repository\QuayRepository;
use App\Service\GtfsRealtimeFetcher;
use App\Service\NextPassageResolver;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Choisit les quais de l'appareil de développement : ceux qui ont le plus de
 * passages dans le flux temps réel, pour que l'écran ait de quoi afficher.
 *
 * La nuit ou si le flux est indisponible, complète avec les quais desservis
 * par le plus de lignes, sans faire échouer le chargement des fixtures.
 */
final readonly class DevelopmentQuaySelector
{
    public function __construct(
        private GtfsRealtimeFetcher $fetcher,
        private NextPassageResolver $resolver,
        private QuayRepository $quayRepository,
    ) {
    }

    public function select(int $count): DevelopmentQuaySelection
    {
        try {
            $passageCounts = $this->resolver->countByStopId($this->fetcher->fetchTripUpdates());
        } catch (HttpClientExceptionInterface|InvalidGtfsRealtimeFeedException $exception) {
            return new DevelopmentQuaySelection(
                $this->mostServed($count, []),
                \sprintf('flux temps réel indisponible (%s)', $exception->getMessage()),
            );
        }

        $quays = $this->quayRepository->findActiveByGtfsStopIds(array_map(strval(...), array_keys($passageCounts)));

        usort($quays, static fn (Quay $a, Quay $b): int => [$passageCounts[$b->getGtfsStopId()], $a->getGtfsStopId()] <=> [$passageCounts[$a->getGtfsStopId()], $b->getGtfsStopId()]);

        $quays = \array_slice($quays, 0, $count);

        if (\count($quays) >= $count) {
            return new DevelopmentQuaySelection($quays);
        }

        return new DevelopmentQuaySelection(
            [...$quays, ...$this->mostServed($count - \count($quays), $quays)],
            \sprintf('%d quai(s) seulement avec des passages dans le flux temps réel', \count($quays)),
        );
    }

    /**
     * @param Quay[] $excluded
     *
     * @return list<Quay>
     */
    private function mostServed(int $count, array $excluded): array
    {
        return array_values($this->quayRepository->findActiveMostServed(
            $count,
            array_map(static fn (Quay $quay): string => $quay->getGtfsStopId(), $excluded),
        ));
    }
}
