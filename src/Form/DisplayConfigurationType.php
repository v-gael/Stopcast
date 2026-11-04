<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * À la création, le dispositif est fixé par l'option « device » ; à l'édition,
 * c'est celui de la configuration. L'interrupteur « Affiché » vient en dernier.
 *
 * @extends AbstractType<DisplayConfiguration>
 */
final class DisplayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $configuration = $builder->getData();
        $editing = $configuration instanceof DisplayConfiguration;

        $builder
            ->add('quay', QuayAutocompleteField::class, [
                'help' => 'Les destinations desservies aident à choisir le bon sens de circulation.',
                'constraints' => [new NotNull(message: 'Choisissez un quai.')],
                // Le quai actuel reste valide même s'il a disparu du dernier import.
                'extra_options' => $editing ? ['kept_quay' => $configuration->getQuay()->getId()] : [],
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'help' => 'Facultatif. Affiché à la place du nom du quai, par exemple « Vers le centre ».',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Affiché',
                'help' => 'Masqué, le quai sort de la rotation sans être supprimé.',
                'required' => false,
                // À la création, rien à lire encore : affiché par défaut, comme l'entité.
                'data' => $editing ? null : true,
                // Dessiné en interrupteur, comme dans la liste de rotation (templates/form/tailwind_layout.html.twig).
                'block_prefix' => 'switch',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DisplayConfiguration::class,
            'device' => null,
            // Le constructeur exige dispositif et quai : sans quai, pas d'objet,
            // et la contrainte NotNull du champ signale le manque.
            'empty_data' => static function (FormInterface $form): ?DisplayConfiguration {
                $device = $form->getConfig()->getOption('device');
                $quay = $form->get('quay')->getData();

                return $device instanceof Device && $quay instanceof Quay ? new DisplayConfiguration($device, $quay) : null;
            },
        ]);
        $resolver->setAllowedTypes('device', [Device::class, 'null']);
    }
}
