<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Email et rôle d'une personne à inviter. Le compte n'existe qu'à la
 * soumission : l'email ne se modifie pas ensuite, il passe au constructeur.
 *
 * @extends AbstractType<User>
 */
final class InviteUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email',
            'priority' => 1,
            'empty_data' => '',
            // Fixé par le constructeur : ne jamais le réécrire.
            'setter' => static function (): void {},
        ]);
        $builder->get('role')->setData(Role::User);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => static function (FormInterface $form): User {
                $email = $form->get('email')->getData();
                $role = $form->get('role')->getData();

                return new User(\is_string($email) ? $email : '', $role instanceof Role ? $role : Role::User);
            },
        ]);
    }

    public function getParent(): string
    {
        return UserRoleType::class;
    }
}
