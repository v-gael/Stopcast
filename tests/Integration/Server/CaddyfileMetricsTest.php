<?php

declare(strict_types=1);

namespace App\Tests\Integration\Server;

use PHPUnit\Framework\TestCase;

/**
 * Les métriques de Caddy et de FrankenPHP ne sont servies que par le site
 * :9180, lu par Alloy sur le réseau observability : jamais par le site
 * principal, que cloudflared route depuis Internet. bin/smoke-test vérifie
 * aussi le 404 de /metrics sur l'instance déployée.
 */
final class CaddyfileMetricsTest extends TestCase
{
    public function testMetricsAreServedByThePortDedicatedToThem(): void
    {
        $site = $this->siteBlock(':9180');

        self::assertMatchesRegularExpression('/handle \/metrics \{\s*metrics\s*\}/', $site);
        self::assertStringContainsString('respond 404', $site);
    }

    public function testMainSiteDoesNotServeMetrics(): void
    {
        self::assertDoesNotMatchRegularExpression('/^\s*metrics\b/m', $this->siteBlock('{$SERVER_NAME:localhost}'));
    }

    /**
     * Le bloc de premier niveau qui commence par « <adresse> { », jusqu'à son
     * accolade fermante en début de ligne.
     */
    private function siteBlock(string $address): string
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3).'/frankenphp/Caddyfile');
        $pattern = '/^'.preg_quote($address, '/').' \{\n(.*?)^\}/ms';

        self::assertSame(1, preg_match($pattern, $caddyfile, $matches), \sprintf('Site « %s » introuvable dans le Caddyfile.', $address));

        return $matches[1];
    }
}
