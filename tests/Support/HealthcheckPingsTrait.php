<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Monitoring\HealthcheckPinger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait HealthcheckPingsTrait
{
    /**
     * URL des pings envoyés.
     *
     * @var list<string>
     */
    private array $pings = [];

    private function recordingPinger(): HealthcheckPinger
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->pings[] = $url;

            return new MockResponse('OK');
        });

        return new HealthcheckPinger($httpClient, new NullLogger());
    }
}
