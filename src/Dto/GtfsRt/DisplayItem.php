<?php

declare(strict_types=1);

namespace App\Dto\GtfsRt;

final readonly class DisplayItem
{
    /**
     * @param NextPassage[] $nextPassages
     */
    public function __construct(
        public ?string $label,
        public string $quayName,
        public array $nextPassages,
        public bool $quayDeprecated,
    ) {
    }
}
