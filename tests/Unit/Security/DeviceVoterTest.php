<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Device;
use App\Entity\User;
use App\Enum\Role;
use App\Security\Voter\DeviceVoter;
use App\Tests\Support\UserWithIdTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DeviceVoterTest extends TestCase
{
    use UserWithIdTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'consulter' => [DeviceVoter::VIEW];
        yield 'modifier' => [DeviceVoter::EDIT];
        yield 'supprimer' => [DeviceVoter::DELETE];
    }

    #[DataProvider('attributes')]
    public function testOwnerIsGranted(string $attribute): void
    {
        $owner = $this->userWithId(1, 'lea@example.com');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->tokenFor($owner), new Device($owner, 'Salon'), $attribute));
    }

    #[DataProvider('attributes')]
    public function testAnotherUserIsDenied(string $attribute): void
    {
        $device = new Device($this->userWithId(1, 'lea@example.com'), 'Salon');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->tokenFor($this->userWithId(2, 'noe@example.com')), $device, $attribute));
    }

    #[DataProvider('attributes')]
    public function testAdministratorIsDeniedOnAnotherUsersDevice(string $attribute): void
    {
        $device = new Device($this->userWithId(1, 'lea@example.com'), 'Salon');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->tokenFor($this->userWithId(3, 'admin@example.com', Role::Admin)), $device, $attribute));
    }

    public function testOwnerKeepsAccessWhenTheEmailOfTheirSessionDiffers(): void
    {
        $device = new Device($this->userWithId(1, 'lea@example.com'), 'Salon');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->tokenFor($this->userWithId(1, 'lea.nouvelle@example.com')), $device, DeviceVoter::VIEW));
    }

    public function testUnsavedUserIsDenied(): void
    {
        $device = new Device(new User('lea@example.com'), 'Salon');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->tokenFor(new User('noe@example.com')), $device, DeviceVoter::VIEW));
    }

    public function testAnonymousVisitorIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new NullToken(), new Device($this->userWithId(1, 'lea@example.com'), 'Salon'), DeviceVoter::VIEW));
    }

    public function testOtherAttributesAndSubjectsAreNotItsConcern(): void
    {
        $owner = $this->userWithId(1, 'lea@example.com');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->tokenFor($owner), new Device($owner, 'Salon'), 'ROLE_USER'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->tokenFor($owner), $owner, DeviceVoter::VIEW));
    }

    private function vote(TokenInterface $token, mixed $subject, string $attribute): int
    {
        return new DeviceVoter()->vote($token, $subject, [$attribute]);
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
