<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\Role;
use App\Validator\PasswordRequirements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée un compte actif d'office, sans invitation : c'est l'accès de secours.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un compte utilisateur, actif immédiatement',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte, qui sert à se connecter')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, \sprintf('Rôle du compte (%s)', $this->roleValues()), Role::User->value)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, "Mot de passe ; demandé de façon masquée s'il est absent")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $roleOption */
        $roleOption = $input->getOption('role');
        $role = Role::tryFrom($roleOption);

        if (null === $role) {
            $io->error(\sprintf('Rôle inconnu « %s », attendu : %s.', $roleOption, $this->roleValues()));

            return Command::FAILURE;
        }

        $user = new User($email, $role);
        $violations = $this->validator->validate($user);

        if (\count($violations) > 0) {
            $this->reportViolations($io, $violations);

            return Command::FAILURE;
        }

        /** @var string|null $password */
        $password = $input->getOption('password') ?? $this->askPassword($io);

        if (null === $password) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::FAILURE;
        }

        $violations = $this->validator->validate($password, new PasswordRequirements());

        if (\count($violations) > 0) {
            $this->reportViolations($io, $violations);

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Compte %s créé (%s).', $user->getEmail(), $role->value));

        return Command::SUCCESS;
    }

    /**
     * Demandé deux fois sans l'afficher ; null si les deux saisies diffèrent.
     */
    private function askPassword(SymfonyStyle $io): ?string
    {
        $password = $io->askHidden('Mot de passe') ?? '';
        $confirmation = $io->askHidden('Confirmer le mot de passe') ?? '';

        if (!\is_string($password) || $password !== $confirmation) {
            return null;
        }

        return $password;
    }

    private function reportViolations(SymfonyStyle $io, ConstraintViolationListInterface $violations): void
    {
        $messages = [];

        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        $io->error($messages);
    }

    private function roleValues(): string
    {
        return implode(' ou ', array_map(static fn (Role $role): string => $role->value, Role::cases()));
    }
}
