<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Forme sous laquelle un email de compte est stocké et cherché : sans espaces
 * autour, tout en minuscules, partie locale comprise. Deux adresses qui ne
 * diffèrent que par la casse désignent ainsi le même compte.
 */
final class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
