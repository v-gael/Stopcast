<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Saisie du code affiché par un écran à appairer. Le code est vérifié par
 * ScreenPairing, qui compte les saisies fausses.
 *
 * @extends AbstractType<array{code: string}>
 */
final class PairScreenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => "Code affiché sur l'écran",
            'help' => 'Six caractères, par exemple K7F-3QX.',
            'constraints' => [new NotBlank(message: "Saisissez le code affiché sur l'écran.")],
            'attr' => [
                'autocomplete' => 'off',
                'autocapitalize' => 'characters',
                'spellcheck' => 'false',
                'maxlength' => 9,
                'class' => 'font-mono uppercase tracking-widest',
            ],
        ]);
    }
}
