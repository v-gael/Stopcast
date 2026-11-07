<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * siteverify simulé du client turnstile.client en test : seul VALID_TOKEN
 * passe, OUTAGE_TOKEN simule une panne de Cloudflare (erreur 500), tout autre
 * jeton est refusé comme invalide.
 */
final class TurnstileSiteverifyMock
{
    /**
     * Clé de site de test de Turnstile, celle de .env.
     */
    public const string SITE_KEY = '1x00000000000000000000AA';

    /**
     * Le jeton factice des clés de test de Turnstile.
     */
    public const string VALID_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    public const string INVALID_TOKEN = 'invalid-token';

    public const string OUTAGE_TOKEN = 'outage-token';

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
        if (self::OUTAGE_TOKEN === ($body['response'] ?? null)) {
            return new MockResponse('', ['http_code' => 500]);
        }

        $payload = self::VALID_TOKEN === ($body['response'] ?? null)
            ? ['success' => true, 'error-codes' => []]
            : ['success' => false, 'error-codes' => ['invalid-input-response']];

        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
