<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pour une simulation de client HTTP (mock_response_factory) qui laisse partir
 * sur le réseau les URL qu'elle ne simule pas.
 */
trait NetworkResponseTrait
{
    /**
     * Réponse du réseau, par un client réel hors conteneur : celui du
     * conteneur est la simulation.
     *
     * @param array<string, mixed> $options
     */
    private function networkResponse(string $method, string $url, array $options): MockResponse
    {
        $response = HttpClient::create()->request($method, $url, $options);

        return new MockResponse($response->getContent(false), [
            'http_code' => $response->getStatusCode(),
            'response_headers' => $response->getHeaders(false),
        ]);
    }
}
