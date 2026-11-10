<?php

declare(strict_types=1);

namespace App\Entity;

use App\Account\EmailNormalizer;
use App\Enum\Role;
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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant de connexion, normalisé (EmailNormalizer) : les recherches
     * normalisent l'adresse saisie de la même façon.
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
     * Mot de passe haché ; null tant que l'invité ne l'a pas choisi.
     */
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Dernière connexion, ou activation du compte ; null pour un compte en
     * attente, ou créé avec son mot de passe et jamais connecté depuis.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * Envoi de l'avertissement avant suppression pour inactivité ; une
     * connexion le remet à null.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactivityWarnedAt = null;

    public function __construct(string $email, Role $role = Role::User)
    {
        $this->email = EmailNormalizer::normalize($email);
        $this->changeRole($role);
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
     * Même compte, enregistré : l'email peut changer, l'id reste.
     */
    public function isSameAccountAs(self $other): bool
    {
        return null !== $this->id && $this->id === $other->id;
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
        return array_values(array_unique([...$this->roles, Role::User->value]));
    }

    public function isAdmin(): bool
    {
        return \in_array(Role::Admin->value, $this->roles, true);
    }

    public function getRole(): Role
    {
        return $this->isAdmin() ? Role::Admin : Role::User;
    }

    /**
     * Les sessions ouvertes du compte sont déconnectées à leur requête
     * suivante : Security compare leurs rôles à ceux relus en base.
     */
    public function changeRole(Role $role): void
    {
        $this->roles = Role::User === $role ? [] : [$role->value];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Compte en attente : créé par une invitation, sans mot de passe, il ne
     * peut pas se connecter.
     */
    public function isPending(): bool
    {
        return null === $this->password;
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

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * Une connexion garde le compte : elle annule l'avertissement d'inactivité.
     */
    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
        $this->inactivityWarnedAt = null;
    }

    public function getInactivityWarnedAt(): ?\DateTimeImmutable
    {
        return $this->inactivityWarnedAt;
    }

    public function markInactivityWarned(\DateTimeImmutable $at): void
    {
        $this->inactivityWarnedAt = $at;
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
        $data["\0".self::class."\0password"] = null === $this->password ? null : hash('crc32c', $this->password);

        return $data;
    }
}
