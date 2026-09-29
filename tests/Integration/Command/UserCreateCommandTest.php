<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UserCreateCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    private const string PASSWORD = 'un-mot-de-passe-solide';

    public function testCreatesAnActiveUserByDefault(): void
    {
        $tester = $this->command();

        $tester->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);

        $tester->assertCommandIsSuccessful();

        $user = $this->findUser(self::EMAIL);
        self::assertSame([User::ROLE_USER], $user->getRoles());
        self::assertTrue($this->passwordHasher()->isPasswordValid($user, self::PASSWORD));
    }

    public function testCreatesAnAdministrator(): void
    {
        $tester = $this->command();

        $tester->execute(['email' => self::EMAIL, '--role' => User::ROLE_ADMIN, '--password' => self::PASSWORD]);

        $tester->assertCommandIsSuccessful();
        self::assertEqualsCanonicalizing([User::ROLE_ADMIN, User::ROLE_USER], $this->findUser(self::EMAIL)->getRoles());
    }

    public function testAsksThePasswordTwiceWhenNotGiven(): void
    {
        $tester = $this->command();
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => self::EMAIL]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->passwordHasher()->isPasswordValid($this->findUser(self::EMAIL), self::PASSWORD));
    }

    public function testRefusesMismatchedPasswords(): void
    {
        $tester = $this->command();
        $tester->setInputs([self::PASSWORD, 'un-autre-mot-de-passe']);

        $tester->execute(['email' => self::EMAIL]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ne correspondent pas', $tester->getDisplay());
        self::assertSame(0, $this->userRepository()->count());
    }

    public function testRefusesADuplicateEmail(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $tester = $this->command();

        $tester->execute(['email' => self::EMAIL, '--password' => self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Un compte existe déjà avec cet email.', $tester->getDisplay());
        self::assertSame(1, $this->userRepository()->count());
    }

    public function testRefusesAnUnknownRole(): void
    {
        $tester = $this->command();

        $tester->execute(['email' => self::EMAIL, '--role' => 'ROLE_SUPER_ADMIN', '--password' => self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Rôle inconnu', $tester->getDisplay());
        self::assertSame(0, $this->userRepository()->count());
    }

    public function testRefusesAnInvalidEmail(): void
    {
        $tester = $this->command();

        $tester->execute(['email' => 'pas-un-email', '--password' => self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->userRepository()->count());
    }

    public function testRefusesATooShortPassword(): void
    {
        $tester = $this->command();

        $tester->execute(['email' => self::EMAIL, '--password' => 'court']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('au moins 12 caractères', $tester->getDisplay());
        self::assertSame(0, $this->userRepository()->count());
    }

    private function command(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:user:create'));
    }

    private function findUser(string $email): User
    {
        $user = $this->userRepository()->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userRepository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }
}
