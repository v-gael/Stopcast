<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte d'une personne qui gère ses dispositifs depuis l'espace web.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
// "user" est un mot réservé de PostgreSQL.
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_USER = 'ROLE_USER';

    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    public const array ROLES = [self::ROLE_USER, self::ROLE_ADMIN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant de connexion.
     */
    #[ORM\Column(type: Types::STRING, length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /**
     * Rôles en plus de ROLE_USER, que tout compte a d'office.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    /**
     * Mot de passe haché.
     */
    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $role = self::ROLE_USER)
    {
        if (!\in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(\sprintf('Rôle inconnu « %s », attendu : %s.', $role, implode(', ', self::ROLES)));
        }

        $this->email = $email;
        $this->roles = self::ROLE_USER === $role ? [] : [$role];
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Un compte sans email ne peut pas se connecter.');
        }

        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_USER]));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * La session garde une empreinte du hash plutôt que le hash lui-même :
     * Security la compare pour déconnecter les autres sessions après un
     * changement de mot de passe.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
