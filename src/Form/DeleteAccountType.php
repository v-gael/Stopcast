<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/**
 * Suppression du compte connecté : son mot de passe actuel la confirme.
 *
 * @extends AbstractType<array{currentPassword: string}>
 */
final class DeleteAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'constraints' => [new UserPassword(message: 'Le mot de passe actuel est incorrect.')],
                'attr' => ['autocomplete' => 'current-password'],
            ])
        ;
    }
}
