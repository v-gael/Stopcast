<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\PasswordRequirements;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Choix d'un nouveau mot de passe depuis le lien reçu par email, deux fois.
 *
 * @extends AbstractType<array{newPassword: string}>
 */
final class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'help' => PasswordRequirements::HELP,
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirmer le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [new PasswordRequirements()],
            ])
        ;
    }
}
