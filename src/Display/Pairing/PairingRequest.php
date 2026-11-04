<?php

declare(strict_types=1);

namespace App\Display\Pairing;

/**
 * Demande d'appairage d'un écran, telle qu'elle est gardée en cache sous le
 * haché de son secret : le code affiché, l'heure où il l'a été, le code
 * précédent (encore accepté), et le dispositif une fois le code saisi.
 */
final readonly class PairingRequest
{
    public function __construct(
        public string $code,
        public int $issuedAt,
        public ?string $previousCode = null,
        public ?int $deviceId = null,
    ) {
    }

    public function confirmedFor(int $deviceId): self
    {
        return new self($this->code, $this->issuedAt, $this->previousCode, $deviceId);
    }
}
