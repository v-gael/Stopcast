<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Libellé et cadences d'affichage d'un dispositif ; son activation en plus à
 * l'édition (« include_active »).
 *
 * @extends AbstractType<Device>
 */
final class DeviceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'Libellé',
            'help' => 'Pour reconnaître le dispositif, par exemple « Écran de la cuisine ».',
            // Un champ vidé reste une chaîne : NotBlank le signale au lieu d'une erreur de type.
            'empty_data' => '',
        ]);

        $builder->add('screenDuration', IntegerType::class, [
            'label' => 'Durée d\'un écran (secondes)',
            'help' => \sprintf('Temps passé sur chaque écran avant le suivant, de %d à %d secondes (%d par défaut).', Device::MIN_SCREEN_DURATION, Device::MAX_SCREEN_DURATION, Device::DEFAULT_SCREEN_DURATION),
            'attr' => ['min' => Device::MIN_SCREEN_DURATION, 'max' => Device::MAX_SCREEN_DURATION],
            // Un champ vidé reprend la valeur par défaut au lieu d'une erreur de type.
            'empty_data' => (string) Device::DEFAULT_SCREEN_DURATION,
        ]);

        $builder->add('refreshInterval', IntegerType::class, [
            'label' => 'Rafraîchissement des données (secondes)',
            'help' => \sprintf('Intervalle entre deux mises à jour des passages, de %d à %d secondes (%d par défaut).', Device::MIN_REFRESH_INTERVAL, Device::MAX_REFRESH_INTERVAL, Device::DEFAULT_REFRESH_INTERVAL),
            'attr' => ['min' => Device::MIN_REFRESH_INTERVAL, 'max' => Device::MAX_REFRESH_INTERVAL],
            'empty_data' => (string) Device::DEFAULT_REFRESH_INTERVAL,
        ]);

        if ($options['include_active']) {
            $builder->add('active', CheckboxType::class, [
                'label' => 'Actif',
                'help' => 'Désactivé, le dispositif est refusé par l\'API jusqu\'à sa réactivation.',
                'required' => false,
                // Dessinée en interrupteur (templates/form/tailwind_layout.html.twig).
                'block_prefix' => 'switch',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Device::class,
            'include_active' => false,
        ]);
        $resolver->setAllowedTypes('include_active', 'bool');
    }
}
