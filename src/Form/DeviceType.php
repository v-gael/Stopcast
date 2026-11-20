<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use App\Enum\DisplayTheme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Libellé, cadences d'affichage et couleurs de l'écran d'un dispositif ; son activation en plus à
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

        $builder->add('screenDuration', SecondsSliderType::class, [
            'label' => "Durée d'affichage d'un écran",
            'attr' => ['min' => Device::MIN_SCREEN_DURATION, 'max' => Device::MAX_SCREEN_DURATION],
            // Un champ vidé reprend la valeur par défaut au lieu d'une erreur de type.
            'empty_data' => (string) Device::DEFAULT_SCREEN_DURATION,
        ]);

        $builder->add('refreshInterval', SecondsPresetsType::class, [
            'label' => 'Rafraîchissement des données',
            'help' => "Fréquence à laquelle l'écran récupère les horaires à jour.",
            'attr' => ['min' => Device::MIN_REFRESH_INTERVAL, 'max' => Device::MAX_REFRESH_INTERVAL],
            'empty_data' => (string) Device::DEFAULT_REFRESH_INTERVAL,
            'presets' => [30, 60, 120, 300],
        ]);

        $builder->add('theme', EnumType::class, [
            'label' => "Couleurs de l'écran",
            'class' => DisplayTheme::class,
            'choice_label' => static fn (DisplayTheme $theme): string => $theme->label(),
            'expanded' => true,
            // Une vignette d'écran par thème (templates/form/tailwind_layout.html.twig).
            'block_prefix' => 'display_theme',
        ]);

        if ($options['include_active']) {
            $builder->add('active', CheckboxType::class, [
                'label' => 'Actif',
                'help' => "Désactivé, le dispositif est refusé par l'API jusqu'à sa réactivation.",
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
