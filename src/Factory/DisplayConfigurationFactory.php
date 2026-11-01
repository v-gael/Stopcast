<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\DisplayConfiguration;
use App\Repository\QuayRepository;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<DisplayConfiguration>
 */
final class DisplayConfigurationFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly QuayRepository $quayRepository,
    ) {
    }

    public static function class(): string
    {
        return DisplayConfiguration::class;
    }

    protected function defaults(): array
    {
        // On pioche parmi les VRAIS quais déjà importés via app:gtfs:import —
        // jamais de quai fictif généré ici.
        $activeQuays = $this->quayRepository->findAllActive();

        if ([] === $activeQuays) {
            throw new \RuntimeException('Aucun quai actif en base. Lance "make import" avant de charger les fixtures.');
        }

        return [
            'device' => DeviceFactory::new(),
            'quay' => self::faker()->randomElement($activeQuays),
            'label' => self::faker()->optional(0.6)->words(2, true),
            'position' => 0,
            'active' => true,
        ];
    }
}
