<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\Role;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testNewAccountIsPendingUntilItHasAPassword(): void
    {
        $user = new User('alice@stopcast.test');

        self::assertTrue($user->isPending());
        self::assertNull($user->getPassword());

        $user->setPassword('hash');

        self::assertFalse($user->isPending());
    }

    public function testEmailIsStoredNormalized(): void
    {
        self::assertSame('alice@stopcast.test', new User(' Alice@Stopcast.TEST ')->getEmail());
    }

    public function testPendingAccountCanBeSerializedForTheSession(): void
    {
        $user = new User('alice@stopcast.test');

        $restored = unserialize(serialize($user));

        self::assertInstanceOf(User::class, $restored);
        self::assertSame('alice@stopcast.test', $restored->getEmail());
    }

    public function testUserIsPromotedToAdministrator(): void
    {
        $user = new User('alice@stopcast.test');

        $user->changeRole(Role::Admin);

        self::assertTrue($user->isAdmin());
        self::assertSame(Role::Admin, $user->getRole());
        self::assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testAdministratorIsDemotedToUser(): void
    {
        $user = new User('alice@stopcast.test', Role::Admin);

        $user->changeRole(Role::User);

        self::assertFalse($user->isAdmin());
        self::assertSame(Role::User, $user->getRole());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}
