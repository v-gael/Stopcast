<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\Turnstile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demande de réinitialisation du mot de passe : l'email du compte, sous
 * captcha Turnstile. Le captcha est validé avec le formulaire, avant la
 * limite de débit du contrôleur : un captcha raté ne la consomme pas.
 *
 * @extends AbstractType<array{email: string}>
 */
final class ResetPasswordRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [new NotBlank(message: "L'email est obligatoire.")],
                'attr' => ['autocomplete' => 'email'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Turnstile()],
        ]);
    }
}
