<?php

declare(strict_types=1);

namespace App\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signale à Healthchecks.io qu'une tâche planifiée a réussi : c'est l'absence
 * de signal qui alerte. Une tâche peut aussi signaler son échec. Un ping raté
 * ne fait jamais échouer la tâche.
 */
final readonly class HealthcheckPinger
{
    private const float TIMEOUT = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Une URL vide (dev, test, check pas encore créé) n'envoie rien.
     */
    public function ping(string $url): void
    {
        if ('' === $url) {
            return;
        }

        try {
            // timeout ne borne que l'inactivité ; max_duration borne l'appel entier.
            $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT])->getContent();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Healthcheck ping failed: {error}', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Alerte tout de suite, sans attendre l'absence de signal. Une URL vide
     * n'envoie rien, comme pour ping().
     */
    public function pingFailure(string $url): void
    {
        if ('' === $url) {
            return;
        }

        $this->ping($url.'/fail');
    }
}
