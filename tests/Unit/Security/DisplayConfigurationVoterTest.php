<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Enum\Role;
use App\Security\Voter\DisplayConfigurationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DisplayConfigurationVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'modifier' => [DisplayConfigurationVoter::EDIT];
        yield 'supprimer' => [DisplayConfigurationVoter::DELETE];
    }

    #[DataProvider('attributes')]
    public function testDeviceOwnerIsGranted(string $attribute): void
    {
        $owner = new User('lea@example.com');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->tokenFor($owner), $this->configurationOf($owner), $attribute));
    }

    #[DataProvider('attributes')]
    public function testAnotherUserIsDenied(string $attribute): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->tokenFor(new User('noe@example.com')), $this->configurationOf(new User('lea@example.com')), $attribute));
    }

    #[DataProvider('attributes')]
    public function testAdministratorIsDeniedOnAnotherUsersConfiguration(string $attribute): void
    {
        $administrator = new User('admin@example.com', Role::Admin);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->tokenFor($administrator), $this->configurationOf(new User('lea@example.com')), $attribute));
    }

    public function testAnonymousVisitorIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new NullToken(), $this->configurationOf(new User('lea@example.com')), DisplayConfigurationVoter::EDIT));
    }

    public function testOtherAttributesAndSubjectsAreNotItsConcern(): void
    {
        $owner = new User('lea@example.com');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->tokenFor($owner), $this->configurationOf($owner), 'ROLE_USER'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->tokenFor($owner), new Device($owner, 'Salon'), DisplayConfigurationVoter::EDIT));
    }

    private function configurationOf(User $owner): DisplayConfiguration
    {
        return new DisplayConfiguration(new Device($owner, 'Salon'), new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1'));
    }

    private function vote(TokenInterface $token, mixed $subject, string $attribute): int
    {
        return new DisplayConfigurationVoter()->vote($token, $subject, [$attribute]);
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
