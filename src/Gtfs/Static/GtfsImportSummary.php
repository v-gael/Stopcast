<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

/**
 * Bilan chiffré d'un import.
 */
final class GtfsImportSummary implements \Stringable
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $deactivated = 0,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            deactivated: $this->deactivated + $other->deactivated,
        );
    }

    public function __toString(): string
    {
        return \sprintf(
            '%d créé(s), %d mis à jour, %d désactivé(s)',
            $this->created,
            $this->updated,
            $this->deactivated,
        );
    }
}
