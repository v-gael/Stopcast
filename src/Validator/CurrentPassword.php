<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Mot de passe actuel du compte connecté, avec un nombre d'essais limité par
 * compte (limiteur current_password) : remplace UserPassword sur les
 * formulaires de l'espace qui le demandent.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class CurrentPassword extends Constraint
{
    public const string INVALID_PASSWORD_ERROR = 'b0c1c0f4-7e0a-4b8e-9d1c-0d6f2c1f6a41';

    public const string TOO_MANY_ATTEMPTS_ERROR = 'e3a5d7c2-5f1b-4c3e-8a9d-2b7e6f4c1d08';

    protected const array ERROR_NAMES = [
        self::INVALID_PASSWORD_ERROR => 'INVALID_PASSWORD_ERROR',
        self::TOO_MANY_ATTEMPTS_ERROR => 'TOO_MANY_ATTEMPTS_ERROR',
    ];

    public string $message = 'Le mot de passe actuel est incorrect.';

    public string $tooManyAttemptsMessage = "Trop d'essais. Réessayez plus tard.";

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
