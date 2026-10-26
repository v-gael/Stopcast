<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\Role;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUserIsPromotedToAdministrator(): void
    {
        $user = new User('alice@abribus.test');

        $user->changeRole(Role::Admin);

        self::assertTrue($user->isAdmin());
        self::assertSame(Role::Admin, $user->getRole());
        self::assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testAdministratorIsDemotedToUser(): void
    {
        $user = new User('alice@abribus.test', Role::Admin);

        $user->changeRole(Role::User);

        self::assertFalse($user->isAdmin());
        self::assertSame(Role::User, $user->getRole());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}
