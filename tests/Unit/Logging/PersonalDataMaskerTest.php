<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\PersonalDataMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersonalDataMaskerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv4Provider(): iterable
    {
        yield 'seule' => ['203.0.113.42', '203.0.113.0'];
        yield 'dans une phrase' => ['Login failed from 198.51.100.7.', 'Login failed from 198.51.100.0.'];
        yield 'avec un port' => ['peer 192.0.2.250:443 closed', 'peer 192.0.2.0:443 closed'];
        yield 'plusieurs' => ['10.1.2.3, 10.4.5.6', '10.1.2.0, 10.4.5.0'];
    }

    #[DataProvider('ipv4Provider')]
    public function testIpv4IsTruncatedToItsSlash24(string $input, string $expected): void
    {
        self::assertSame($expected, new PersonalDataMasker()->mask($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv6Provider(): iterable
    {
        yield 'complète' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334', '2001:db8:85a3::'];
        yield 'abrégée' => ['client 2001:db8:1234:5678::1 refused', 'client 2001:db8:1234:: refused'];
        yield 'entre crochets avec un port' => ['[2a01:cb00:12:3400::7]:443', '[2a01:cb00:12::]:443'];
        yield 'boucle locale' => ['from ::1', 'from ::'];
        yield 'IPv4 dans IPv6' => ['::ffff:203.0.113.42', '::ffff:203.0.113.0'];
    }

    #[DataProvider('ipv6Provider')]
    public function testIpv6IsTruncatedToItsSlash48(string $input, string $expected): void
    {
        self::assertSame($expected, new PersonalDataMasker()->mask($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emailProvider(): iterable
    {
        yield 'simple' => ['jeanne@example.com', 'j***@e***.com'];
        yield 'domaine à plusieurs niveaux' => ['lea.martin+stopcast@mail.gaelv.co.uk', 'l***@m***.uk'];
        yield 'entre guillemets' => ['User "Bob@Example.org" not found', 'User "B***@E***.org" not found'];
        yield 'plusieurs' => ['a@b.fr, cecile@d.io', 'a***@b***.fr, c***@d***.io'];
        yield 'déjà masqué' => ['j***@e***.com', 'j***@e***.com'];
    }

    public function testPasswordResetTokenIsReplaced(): void
    {
        self::assertSame(
            '"GET /reset-password/reset/*** HTTP/1.1" then /reset-password/reset',
            new PersonalDataMasker()->mask('"GET /reset-password/reset/Xk3vQ9pLmN2rT7wYb5cH8jF4dS6aG1eZ HTTP/1.1" then /reset-password/reset'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouchedProvider(): iterable
    {
        yield 'version' => ['symfony/http-kernel 8.1.9'];
        yield 'quatre nombres suivis d\'un cinquième' => ['OID 1.3.6.1.4.1'];
        yield 'heure' => ['2026-11-06T10:20:30+01:00'];
        yield 'appel statique PHP' => ['App\Gtfs\Importer::add() failed in Foo::bar'];
        yield 'adresse MAC' => ['aa:bb:cc:dd:ee:ff'];
        yield 'UUID' => ['550e8400-e29b-41d4-a716-446655440000'];
        yield 'arobase sans domaine' => ['@deprecated since 2.0'];
    }

    #[DataProvider('untouchedProvider')]
    public function testTextWithoutPersonalDataIsLeftUnchanged(string $input): void
    {
        self::assertSame($input, new PersonalDataMasker()->mask($input));
    }

    #[DataProvider('emailProvider')]
    public function testEmailKeepsOnlyItsFirstLettersAndTopLevelDomain(string $input, string $expected): void
    {
        self::assertSame($expected, new PersonalDataMasker()->mask($input));
    }
}
