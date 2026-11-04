<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Durée en secondes choisie parmi des valeurs préréglées (option « presets »),
 * ou saisie après « Autre » (bloc « seconds_presets » de
 * templates/form/tailwind_layout.html.twig). Sans JavaScript, seul le champ
 * nombre s'affiche.
 *
 * @extends AbstractType<int>
 */
final class SecondsPresetsType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['presets'] = $options['presets'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('presets');
        $resolver->setAllowedTypes('presets', 'int[]');
    }

    public function getParent(): string
    {
        return IntegerType::class;
    }
}
