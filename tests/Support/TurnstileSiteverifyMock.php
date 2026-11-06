<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * siteverify simulé du client turnstile.client en test : seul VALID_TOKEN
 * passe, tout autre jeton est refusé comme invalide.
 */
final class TurnstileSiteverifyMock
{
    /**
     * Le jeton factice des clés de test de Turnstile.
     */
    public const string VALID_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    public const string INVALID_TOKEN = 'invalid-token';

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
        $payload = self::VALID_TOKEN === ($body['response'] ?? null)
            ? ['success' => true, 'error-codes' => []]
            : ['success' => false, 'error-codes' => ['invalid-input-response']];

        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
