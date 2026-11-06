<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\User;
use App\Enum\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Règles d'access_control lues telles que le firewall les applique. Aucune
 * page n'existe encore sous /admin : une requête HTTP y finirait en 404
 * avant le contrôle d'accès.
 */
final class AccessControlTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, Role, bool}>
     */
    public static function accesses(): iterable
    {
        yield 'utilisateur sur /admin' => ['/admin/users', Role::User, false];
        yield 'administrateur sur /admin' => ['/admin/users', Role::Admin, true];
        yield 'utilisateur sur son compte' => ['/account', Role::User, true];
        yield 'administrateur sur son compte' => ['/account', Role::Admin, true];
        yield 'utilisateur sur ses dispositifs' => ['/devices', Role::User, true];
        yield 'utilisateur sur ses configurations' => ['/display-configurations/new', Role::User, true];
    }

    #[DataProvider('accesses')]
    public function testAccessDependsOnTheRole(string $path, Role $role, bool $granted): void
    {
        $request = Request::create($path);
        $accessMap = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);
        [$attributes] = $accessMap->getPatterns($request);
        self::assertIsArray($attributes);

        $decisionManager = self::getContainer()->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $decisionManager);

        $user = new User('lea@example.com', $role);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame($granted, $decisionManager->decide($token, $attributes, $request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPaths(): iterable
    {
        yield 'vitrine' => ['/'];
        // Sans cookie, l'écran y reçoit l'écran d'appairage.
        yield "page d'affichage" => ['/display'];
    }

    #[DataProvider('publicPaths')]
    public function testPublicPathsHaveNoRule(string $path): void
    {
        $accessMap = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);

        [$attributes] = $accessMap->getPatterns(Request::create($path));

        self::assertNull($attributes);
    }
}
