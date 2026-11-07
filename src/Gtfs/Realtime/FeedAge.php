<?php

declare(strict_types=1);

namespace App\Gtfs\Realtime;

/**
 * Âge d'un flux temps réel, d'après son header.timestamp.
 */
final readonly class FeedAge
{
    /**
     * @param \DateTimeImmutable|null $publishedAt header.timestamp, dans le fuseau de l'horloge ; null s'il est absent (0)
     * @param int                     $seconds     0 si le timestamp est absent ou dans le futur
     */
    public function __construct(
        public ?\DateTimeImmutable $publishedAt,
        public int $seconds,
        public bool $fresh,
    ) {
    }
}
