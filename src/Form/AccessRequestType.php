<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccessRequest;
use App\Validator\Turnstile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demande d'accès d'un visiteur : son email et un message facultatif, sous
 * captcha Turnstile. Le captcha est validé avec le formulaire, avant la
 * limite de débit du contrôleur : un captcha raté ne la consomme pas.
 *
 * @extends AbstractType<array{email: string, message: ?string}>
 */
final class AccessRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(message: "L'email est obligatoire."),
                    new Email(message: "Cette adresse email n'est pas valide."),
                    new Length(max: 180),
                ],
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message (facultatif)',
                'required' => false,
                'help' => 'Ce qui vous amène : votre contexte, la personne qui vous recommande…',
                'constraints' => [
                    new Length(max: AccessRequest::MESSAGE_MAX_LENGTH, maxMessage: 'Le message ne doit pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => ['rows' => 4, 'maxlength' => AccessRequest::MESSAGE_MAX_LENGTH],
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
