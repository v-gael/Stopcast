<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Captcha Turnstile d'un formulaire public : le jeton cf-turnstile-response
 * que le widget ajoute à la requête. Posée sur le formulaire entier, elle
 * affiche son erreur au-dessus des champs.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Turnstile extends Constraint
{
    public const string FAILED_ERROR = '6f1d3c8a-2b4e-4f7a-9c5d-8e0b1a2f3d4c';

    protected const array ERROR_NAMES = [
        self::FAILED_ERROR => 'FAILED_ERROR',
    ];

    public string $message = 'La vérification anti-robot a échoué. Réessayez.';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
