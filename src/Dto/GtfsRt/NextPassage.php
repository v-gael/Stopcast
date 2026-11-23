<?php

declare(strict_types=1);

namespace App\Dto\GtfsRt;

final readonly class NextPassage
{
    /**
     * Délai pendant lequel un passage dont l'heure annoncée est dépassée reste
     * attendu. Le flux liste le quai jusqu'au départ réel du bus, souvent après
     * l'heure annoncée : seul un passage dépassé de plus que ce délai est tenu
     * pour parti, même si le flux le liste encore (trajet resté dans le flux,
     * ou revenu avec des heures figées).
     */
    public const int OVERDUE_GRACE_SECONDS = 300;

    public function __construct(
        public string $routeShortName,
        public string $routeColor,
        public string $routeTextColor,
        public ?string $headsign,
        public \DateTimeImmutable $expectedAt,
    ) {
    }

    public function isStillExpectedAt(\DateTimeImmutable $now): bool
    {
        return self::isStillExpected($this->expectedAt, $now);
    }

    /**
     * Un passage annoncé à $expectedAt est-il encore attendu à $now ?
     */
    public static function isStillExpected(\DateTimeImmutable $expectedAt, \DateTimeImmutable $now): bool
    {
        return $expectedAt->getTimestamp() >= $now->getTimestamp() - self::OVERDUE_GRACE_SECONDS;
    }
}
