<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use App\Enum\Role;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * « password » reçoit le mot de passe en clair : il est haché à l'instanciation (null : compte en attente).
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'mot-de-passe-de-test';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'role' => Role::User,
            'password' => self::DEFAULT_PASSWORD,
        ];
    }

    /**
     * Compte invité qui n'a pas encore choisi son mot de passe.
     */
    public function pending(): static
    {
        return $this->with(['password' => null]);
    }

    protected function initialize(): static
    {
        return $this
            // setPassword() n'accepte pas null : le mot de passe s'écrit directement.
            ->instantiateWith(Instantiator::withConstructor()->alwaysForce('password'))
            ->afterInstantiate(function (User $user): void {
                $password = $user->getPassword();
                if (null !== $password) {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                }
            });
    }
}
