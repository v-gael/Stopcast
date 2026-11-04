<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Choix du dispositif qui affichera l'écran dont le QR code a été scanné,
 * parmi ceux du compte (« devices »). Le code, dans l'adresse, est vérifié
 * par ScreenPairing.
 *
 * @extends AbstractType<array{device: ?Device}>
 */
final class ScannedPairingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('device', EntityType::class, [
            'label' => 'Dispositif',
            'class' => Device::class,
            'choices' => $options['devices'],
            'choice_label' => static fn (Device $device): string => $device->hasPairedScreen()
                ? \sprintf('%s (écran appairé)', $device->getLabel())
                : $device->getLabel(),
            'expanded' => true,
            'help' => "L'écran appairé d'un dispositif est remplacé par celui-ci.",
            'constraints' => [new NotNull(message: 'Choisissez le dispositif qui affichera cet écran.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('devices');
        $resolver->setAllowedTypes('devices', Device::class.'[]');
    }
}
