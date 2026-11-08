<?php

declare(strict_types=1);

namespace App\Display\Pairing;

/**
 * Code d'appairage montré par un écran et saisi par le propriétaire : six
 * caractères sans ceux qu'on confond à la lecture (0/O, 1/I/L), affichés en
 * deux groupes, « K7F-3QX ». Ce n'est qu'un libellé : l'écran ne s'en sert pas
 * pour interroger le serveur.
 */
final class PairingCode
{
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const int LENGTH = 6;

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * Code tel qu'il est saisi, sans casse, espaces ni tiret ; null s'il ne
     * peut pas être un code.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $input));

        return self::LENGTH === \strlen($code) && \strlen($code) === strspn($code, self::ALPHABET) ? $code : null;
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 3).'-'.substr($code, 3);
    }
}
