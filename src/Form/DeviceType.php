<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Libellé d'un dispositif ; son activation en plus à l'édition (« include_active »).
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

        if ($options['include_active']) {
            $builder->add('active', CheckboxType::class, [
                'label' => 'Actif',
                'help' => 'Désactivé, le dispositif est refusé par l\'API jusqu\'à sa réactivation.',
                'required' => false,
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
