<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DisplayConfiguration;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Une configuration d'affichage n'est accessible qu'au propriétaire de son dispositif,
 * administrateurs compris. Changer sa place dans la rotation relève de EDIT.
 *
 * @extends Voter<string, DisplayConfiguration>
 */
final class DisplayConfigurationVoter extends Voter
{
    public const string EDIT = 'DISPLAY_CONFIGURATION_EDIT';

    public const string DELETE = 'DISPLAY_CONFIGURATION_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof DisplayConfiguration;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Même comparaison par email que DeviceVoter.
        return $user instanceof User && $subject->getDevice()->getOwner()->getUserIdentifier() === $user->getUserIdentifier();
    }
}
