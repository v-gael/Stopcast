<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rôle principal d'un compte. La valeur est le rôle Symfony qu'il accorde.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Utilisateur',
            self::Admin => 'Administrateur',
        };
    }
}
