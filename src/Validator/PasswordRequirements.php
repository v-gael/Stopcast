<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Règles d'un nouveau mot de passe, partout où on en choisit un : commande
 * app:user:create, page « Mon compte », invitation et mot de passe oublié.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PasswordRequirements extends Compound
{
    public const int MIN_LENGTH = 12;

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Assert\NotBlank|Assert\Length>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
            new Assert\Length(
                min: self::MIN_LENGTH,
                // Plafond de Symfony : au-delà, le hachage coûte trop cher.
                max: 4096,
                minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
            ),
        ];
    }
}
