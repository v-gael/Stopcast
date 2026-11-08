<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Device;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un dispositif n'est accessible qu'à son propriétaire, administrateurs compris.
 *
 * @extends Voter<string, Device>
 */
final class DeviceVoter extends Voter
{
    public const string VIEW = 'DEVICE_VIEW';

    public const string EDIT = 'DEVICE_EDIT';

    public const string DELETE = 'DEVICE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof Device;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // L'email identifie le compte et ne change pas : il se compare aussi hors
        // base, contrairement à un id encore nul.
        return $user instanceof User && $subject->getOwner()->getUserIdentifier() === $user->getUserIdentifier();
    }
}
