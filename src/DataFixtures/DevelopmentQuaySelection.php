<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Quay;

final readonly class DevelopmentQuaySelection
{
    /**
     * @param list<Quay>  $quays
     * @param string|null $fallbackReason pourquoi le classement temps réel a été complété, null s'il a suffi
     */
    public function __construct(
        public array $quays,
        public ?string $fallbackReason = null,
    ) {
    }
}
