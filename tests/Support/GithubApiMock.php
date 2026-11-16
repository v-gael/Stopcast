<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client github.client simulé en test, celui d'asset_mapper : garde l'URL et
 * l'en-tête Authorization de chaque requête. L'API GitHub répond une liste
 * d'advisories vide ; toute autre URL part sur le réseau, comme sans
 * simulation : importmap:install télécharge les paquets par ce client, et
 * tourne en environnement de test dans la CI.
 */
final class GithubApiMock
{
    use NetworkResponseTrait;

    /**
     * Le jeton de GITHUB_ADVISORIES_TOKEN dans .env.test.
     */
    public const string TOKEN = 'github-advisories-test-token';

    private const string API_URL = 'https://api.github.com/';

    private bool $offline = false;

    /**
     * @var list<array{url: string, authorization: ?string}>
     */
    private array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $authorization = \is_array($headers['authorization'] ?? null) ? $headers['authorization'][0] : null;
        $this->requests[] = ['url' => $url, 'authorization' => \is_string($authorization) ? $authorization : null];

        if (str_starts_with($url, self::API_URL)) {
            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        }

        if ($this->offline) {
            return new MockResponse('', ['http_code' => 404]);
        }

        return $this->networkResponse($method, $url, $options);
    }

    /**
     * Répond 404 à toute URL hors de l'API GitHub, sans le réseau.
     */
    public function goOffline(): void
    {
        $this->offline = true;
    }

    /**
     * @return list<array{url: string, authorization: ?string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
