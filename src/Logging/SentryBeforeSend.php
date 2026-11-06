<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * before_send de Sentry : écarte les erreurs client, puis masque le reste.
 *
 * Symfony journalise toute exception HTTP 4xx au niveau error. Une erreur
 * client (jeton refusé, accès interdit, limite de débit) n'est pas une panne,
 * et elle consommerait le quota ; ignore_exceptions ne filtre que par classe,
 * pas par statut.
 */
final readonly class SentryBeforeSend
{
    public function __construct(
        private SentryEventScrubber $scrubber,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        return ($this->scrubber)($event);
    }
}
