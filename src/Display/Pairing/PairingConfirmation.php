<?php

declare(strict_types=1);

namespace App\Display\Pairing;

/**
 * Issue de la saisie d'un code d'appairage par le propriétaire d'un dispositif.
 */
enum PairingConfirmation
{
    /** L'écran recevra son cookie à sa prochaine interrogation. */
    case Confirmed;

    /** Code inconnu, expiré ou déjà utilisé. */
    case UnknownCode;

    /** Trop de saisies fausses : même un bon code est refusé un moment. */
    case TooManyAttempts;
}
