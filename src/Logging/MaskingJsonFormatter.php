<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Formateur JSON des logs de prod qui masque IP et emails dans la ligne
 * finale : le processor ne peut pas réécrire le message d'une exception, que
 * JsonFormatter sérialise tel quel.
 */
final class MaskingJsonFormatter extends JsonFormatter
{
    public function __construct(
        private readonly PersonalDataMasker $masker,
    ) {
        parent::__construct();
    }

    public function format(LogRecord $record): string
    {
        return $this->masker->mask(parent::format($record));
    }

    public function formatBatch(array $records): string
    {
        return $this->masker->mask(parent::formatBatch($records));
    }
}
