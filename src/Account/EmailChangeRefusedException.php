<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Demande de changement d'email refusée ; le message s'affiche tel quel dans
 * le formulaire.
 */
final class EmailChangeRefusedException extends \RuntimeException
{
}
