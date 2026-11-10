<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\Role;

/**
 * Compte doté d'un id sans passer par la base, pour les tests unitaires qui
 * comparent des comptes.
 */
trait UserWithIdTrait
{
    private function userWithId(int $id, string $email, Role $role = Role::User): User
    {
        $user = new User($email, $role);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
