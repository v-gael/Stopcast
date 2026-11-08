<?php

declare(strict_types=1);

namespace App\Dto\GtfsRt;

final readonly class NextPassage
{
    public function __construct(
        public string $routeShortName,
        public string $routeColor,
        public string $routeTextColor,
        public ?string $headsign,
        public \DateTimeImmutable $expectedAt,
    ) {
    }
}
