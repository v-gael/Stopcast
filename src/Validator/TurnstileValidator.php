<?php

declare(strict_types=1);

namespace App\Validator;

use App\Security\TurnstileVerifier;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Vérifie le jeton Turnstile de la requête en cours, quelle que soit la
 * valeur validée.
 */
final class TurnstileValidator extends ConstraintValidator
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TurnstileVerifier $turnstileVerifier,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Turnstile) {
            throw new UnexpectedTypeException($constraint, Turnstile::class);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof \Symfony\Component\HttpFoundation\Request && $this->turnstileVerifier->verify($request, $constraint->action)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(Turnstile::FAILED_ERROR)
            ->addViolation();
    }
}
