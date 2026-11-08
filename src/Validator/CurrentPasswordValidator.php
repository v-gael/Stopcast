<?php

declare(strict_types=1);

namespace App\Validator;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Vérifie le mot de passe comme UserPassword, après le limiteur du compte :
 * un mot de passe faux consomme un essai, un juste remet le compteur à zéro,
 * et plus aucun essai n'est vérifié une fois la limite atteinte.
 */
final class CurrentPasswordValidator extends ConstraintValidator
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Target('current_password')]
        private readonly RateLimiterFactoryInterface $currentPasswordLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CurrentPassword) {
            throw new UnexpectedTypeException($constraint, CurrentPassword::class);
        }

        if (null !== $value && !\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        $user = $this->security->getUser();
        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new ConstraintDefinitionException(\sprintf('The "%s" class must implement the "%s" interface.', get_debug_type($user), PasswordAuthenticatedUserInterface::class));
        }

        $limiter = $this->currentPasswordLimiter->create($user->getUserIdentifier());

        // consume(0) lit l'état sans consommer d'essai.
        if (0 === $limiter->consume(0)->getRemainingTokens()) {
            $this->logger->warning('Too many current password attempts for {account} on form {form}.', [
                'account' => $user->getUserIdentifier(),
                'form' => $this->formName(),
            ]);
            $this->context->buildViolation($constraint->tooManyAttemptsMessage)
                ->setCode(CurrentPassword::TOO_MANY_ATTEMPTS_ERROR)
                ->addViolation();

            return;
        }

        if (null === $value || '' === $value || !$this->passwordHasher->isPasswordValid($user, $value)) {
            $limiter->consume();
            $this->context->buildViolation($constraint->message)
                ->setCode(CurrentPassword::INVALID_PASSWORD_ERROR)
                ->addViolation();

            return;
        }

        $limiter->reset();
    }

    private function formName(): string
    {
        $root = $this->context->getRoot();

        return $root instanceof FormInterface ? $root->getName() : get_debug_type($root);
    }
}
