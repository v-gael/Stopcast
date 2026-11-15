<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Chaque limite d'essais est verrouillée par lock.factory (lock.yaml, sur
 * Redis hors test). RedisRateLimiterBurstTest montre qu'avec ce verrou une
 * rafale parallèle ne dépasse pas la limite ; ce test vérifie qu'aucun limiter
 * de la configuration ne s'en passe. La suite ne le voit pas autrement : en
 * test, les compteurs sont en mémoire, dans un seul processus.
 */
final class RateLimiterLockTest extends TestCase
{
    private const string PACKAGES = __DIR__.'/../../../config/packages';

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function limiters(): iterable
    {
        foreach (self::section('rate_limiter.yaml', 'framework', 'rate_limiter') as $name => $limiter) {
            yield 'rate_limiter.yaml : '.$name => [$limiter];
        }

        foreach (self::section('security.yaml', 'security', 'firewalls') as $name => $firewall) {
            if (\is_array($firewall) && \array_key_exists('login_throttling', $firewall)) {
                yield 'security.yaml : login_throttling du firewall '.$name => [$firewall['login_throttling']];
            }
        }
    }

    #[DataProvider('limiters')]
    public function testTheLimiterIsLocked(mixed $limiter): void
    {
        self::assertIsArray($limiter);
        self::assertSame(
            'lock.factory',
            $limiter['lock_factory'] ?? null,
            'Sans verrou, des essais simultanés dépassent la limite (lire-modifier-écrire non atomique) : renseigner lock_factory: lock.factory.',
        );
    }

    /**
     * @return array<mixed>
     */
    private static function section(string $file, string ...$keys): array
    {
        $data = Yaml::parseFile(self::PACKAGES.'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data, \sprintf('%s : clé %s introuvable.', $file, $key));
            $data = $data[$key];
        }

        self::assertIsArray($data);

        return $data;
    }
}
