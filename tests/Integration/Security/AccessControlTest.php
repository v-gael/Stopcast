<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\User;
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
     * @return iterable<string, array{string, string, bool}>
     */
    public static function accesses(): iterable
    {
        yield 'utilisateur sur /admin' => ['/admin/users', User::ROLE_USER, false];
        yield 'administrateur sur /admin' => ['/admin/users', User::ROLE_ADMIN, true];
        yield 'utilisateur sur son compte' => ['/account', User::ROLE_USER, true];
        yield 'administrateur sur son compte' => ['/account', User::ROLE_ADMIN, true];
        yield 'utilisateur sur ses dispositifs' => ['/devices', User::ROLE_USER, true];
        yield 'utilisateur sur ses configurations' => ['/display-configurations', User::ROLE_USER, true];
        yield 'utilisateur sur /api/next-passages' => ['/api/next-passages', User::ROLE_ADMIN, false];
    }

    #[DataProvider('accesses')]
    public function testAccessDependsOnTheRole(string $path, string $role, bool $granted): void
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
        yield 'documentation' => ['/api'];
        yield 'spécification OpenAPI' => ['/api/docs.jsonopenapi'];
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
