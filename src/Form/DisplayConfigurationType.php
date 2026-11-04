<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * À la création (« owner »), le dispositif est choisi parmi ceux du compte ;
 * à l'édition, il est fixé et l'activation s'ajoute.
 *
 * @extends AbstractType<DisplayConfiguration>
 */
final class DisplayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $owner = $options['owner'];

        if ($owner instanceof User) {
            $builder->add('device', EntityType::class, [
                'class' => Device::class,
                'label' => 'Dispositif',
                'placeholder' => 'Choisir un dispositif',
                'choice_label' => 'label',
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('device')
                    ->andWhere('device.owner = :owner')
                    ->setParameter('owner', $owner)
                    ->orderBy('device.label', 'ASC'),
                'constraints' => [new NotNull(message: 'Choisissez un dispositif.')],
            ]);
        }

        $configuration = $builder->getData();

        $builder
            ->add('quay', QuayAutocompleteField::class, [
                'help' => 'Les destinations desservies aident à choisir le bon sens de circulation.',
                'constraints' => [new NotNull(message: 'Choisissez un quai.')],
                // Le quai actuel reste valide même s'il a disparu du dernier import.
                'extra_options' => $configuration instanceof DisplayConfiguration ? ['kept_quay' => $configuration->getQuay()->getId()] : [],
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'help' => 'Facultatif. Affiché à la place du nom du quai, par exemple « Vers le centre ».',
                'required' => false,
            ]);

        if (!$owner instanceof User) {
            $builder->add('active', CheckboxType::class, [
                'label' => 'Affichée',
                'help' => 'Masquée, la configuration sort de la rotation sans être supprimée.',
                'required' => false,
                // Dessinée en interrupteur, comme dans la liste de rotation (templates/form/tailwind_layout.html.twig).
                'block_prefix' => 'switch',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DisplayConfiguration::class,
            'owner' => null,
            // Le constructeur exige dispositif et quai : sans l'un d'eux, pas d'objet,
            // et les contraintes NotNull des champs signalent le manque.
            'empty_data' => static function (FormInterface $form): ?DisplayConfiguration {
                $device = $form->has('device') ? $form->get('device')->getData() : null;
                $quay = $form->get('quay')->getData();

                return $device instanceof Device && $quay instanceof Quay ? new DisplayConfiguration($device, $quay) : null;
            },
        ]);
        $resolver->setAllowedTypes('owner', [User::class, 'null']);
    }
}
