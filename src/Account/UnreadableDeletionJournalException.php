<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Une ligne du journal des suppressions ne se lit pas : plutôt que de la
 * sauter et laisser revenir un compte supprimé, le rejeu s'arrête.
 */
final class UnreadableDeletionJournalException extends \RuntimeException
{
    public function __construct(
        public readonly int $lineNumber,
    ) {
        parent::__construct(\sprintf('Line %d of the deletion journal cannot be read.', $lineNumber));
    }
}
