<?php

declare(strict_types=1);

namespace App\Display\Pairing;

/**
 * Où en est l'appairage d'un dispositif, gardé en cache sous son identifiant :
 * code saisi ou écran appairé, et depuis quand (timestamp).
 */
final readonly class PairingProgress
{
    private function __construct(
        public bool $screenPaired,
        public int $since,
    ) {
    }

    public static function codeEntered(int $at): self
    {
        return new self(false, $at);
    }

    public static function screenPaired(int $at): self
    {
        return new self(true, $at);
    }
}
