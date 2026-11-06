<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masque IP et emails dans le message, le contexte et les données ajoutées de
 * chaque log, sur tous les canaux. L'exception du contexte reste intacte pour
 * Sentry : son message est masqué par MaskingJsonFormatter et SentryEventScrubber.
 */
final readonly class PersonalDataMaskingProcessor implements ProcessorInterface
{
    public function __construct(
        private PersonalDataMasker $masker,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->masker->mask($record->message),
            context: $this->masker->maskValues($record->context),
            extra: $this->masker->maskValues($record->extra),
        );
    }
}
