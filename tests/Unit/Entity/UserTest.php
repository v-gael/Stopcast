<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUserIsPromotedToAdministrator(): void
    {
        $user = new User('alice@abribus.test');

        $user->changeRole(User::ROLE_ADMIN);

        self::assertTrue($user->isAdmin());
        self::assertSame(User::ROLE_ADMIN, $user->getRole());
        self::assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testAdministratorIsDemotedToUser(): void
    {
        $user = new User('alice@abribus.test', User::ROLE_ADMIN);

        $user->changeRole(User::ROLE_USER);

        self::assertFalse($user->isAdmin());
        self::assertSame(User::ROLE_USER, $user->getRole());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testUnknownRoleIsRejected(): void
    {
        $user = new User('alice@abribus.test');

        $this->expectException(\InvalidArgumentException::class);

        $user->changeRole('ROLE_SUPER_ADMIN');
    }
}
