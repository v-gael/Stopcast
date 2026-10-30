<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * En-têtes de sécurité posés par nelmio/security-bundle sur toutes les
 * réponses, et CSP à nonces des pages HTML.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testPageCarriesAStrictNonceBasedPolicy(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $nonce = $this->nonceOf($crawler);
        self::assertResponseHeaderSame('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data:",
            "object-src 'none'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
        ]));
        self::assertResponseNotHasHeader('Content-Security-Policy-Report-Only');
        self::assertResponseNotHasHeader('X-Content-Security-Policy');
        // Turbo lit le nonce de la page pour sa barre de progression.
        self::assertSame($nonce, $crawler->filter('meta[name="csp-nonce"]')->attr('content'));
        self::assertSame($nonce, $crawler->filter('script[type="module"]')->attr('nonce'));
    }

    public function testEachPageGetsItsOwnNonce(): void
    {
        $client = self::createClient();

        $first = $this->nonceOf($client->request('GET', '/'));
        $second = $this->nonceOf($client->request('GET', '/'));

        self::assertNotSame($first, $second);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function responses(): iterable
    {
        yield 'page HTML' => ['/'];
        yield "documentation de l'API" => ['/api/docs.jsonopenapi'];
        yield 'API refusée sans jeton' => ['/api/next-passages'];
    }

    #[DataProvider('responses')]
    public function testEveryResponseCarriesTheSecurityHeaders(string $path): void
    {
        $client = self::createClient();

        $client->request('GET', $path);

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        // API Platform pose déjà « deny » sur ses réponses, que le bundle garde.
        self::assertSame('DENY', strtoupper((string) $client->getResponse()->headers->get('X-Frame-Options')));
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertResponseHeaderSame('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        self::assertResponseHeaderSame('Cross-Origin-Opener-Policy', 'same-origin');
        // HSTS est posé par le serveur qui termine le TLS, pas par l'application.
        self::assertResponseNotHasHeader('Strict-Transport-Security');
    }

    /**
     * Nonce du <script type="importmap"> de la page.
     */
    private function nonceOf(Crawler $crawler): string
    {
        $nonce = $crawler->filter('script[type="importmap"]')->attr('nonce');
        self::assertNotNull($nonce);
        self::assertNotSame('', $nonce);

        return $nonce;
    }
}
