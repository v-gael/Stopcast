<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\CurrentPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changement d'email du compte connecté : la nouvelle adresse, confirmée par
 * son mot de passe actuel.
 *
 * @extends AbstractType<array{newEmail: string, currentPassword: string}>
 */
final class ChangeEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('newEmail', EmailType::class, [
                'label' => 'Nouvelle adresse',
                'constraints' => [
                    new NotBlank(message: "L'email est obligatoire."),
                    new Email(),
                    new Length(max: 180),
                ],
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'constraints' => [new CurrentPassword()],
                'attr' => ['autocomplete' => 'current-password'],
            ])
        ;
    }
}
