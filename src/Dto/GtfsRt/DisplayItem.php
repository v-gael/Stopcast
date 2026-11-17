<?php

declare(strict_types=1);

namespace App\Dto\GtfsRt;

final readonly class DisplayItem
{
    /**
     * @param string        $displayName  DisplayConfiguration::getDisplayName()
     * @param NextPassage[] $nextPassages
     */
    public function __construct(
        public string $displayName,
        public array $nextPassages,
        public bool $quayDeprecated,
    ) {
    }
}
