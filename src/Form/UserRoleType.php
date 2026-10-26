<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Rôle d'un compte, seul champ qu'un administrateur modifie : l'email ne change pas.
 *
 * @extends AbstractType<User>
 */
final class UserRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('role', ChoiceType::class, [
            'label' => 'Rôle',
            'choices' => [
                'Utilisateur' => User::ROLE_USER,
                'Administrateur' => User::ROLE_ADMIN,
            ],
            'expanded' => true,
            'getter' => static fn (User $user): string => $user->getRole(),
            'setter' => static fn (User $user, string $role) => $user->changeRole($role),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
