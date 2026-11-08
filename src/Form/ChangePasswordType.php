<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\CurrentPassword;
use App\Validator\PasswordRequirements;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Changement de mot de passe du compte connecté : l'actuel, puis le nouveau deux fois.
 *
 * @extends AbstractType<array{currentPassword: string, newPassword: string}>
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'constraints' => [new CurrentPassword()],
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'help' => \sprintf('%d caractères minimum.', PasswordRequirements::MIN_LENGTH),
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
