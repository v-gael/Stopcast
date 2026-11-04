<?php

declare(strict_types=1);

namespace App\Display\Pairing;

/**
 * Écran en attente d'appairage : le secret de sa demande, à garder dans son
 * cookie, et le code à afficher jusqu'à son expiration.
 */
final readonly class PendingPairing
{
    public function __construct(
        #[\SensitiveParameter]
        public string $requestSecret,
        public string $code,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
