<?php

declare(strict_types=1);

namespace App\Entity;

use App\Account\EmailNormalizer;
use App\Repository\AccessRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'accès d'un visiteur, en attendant qu'un administrateur l'invite
 * ou la supprime. Ce n'est pas un compte : seule l'invitation en crée un.
 */
#[ORM\Entity(repositoryClass: AccessRequestRepository::class)]
#[ORM\Table(name: 'access_request')]
#[ORM\UniqueConstraint(name: 'uniq_access_request_email', columns: ['email'])]
class AccessRequest
{
    public const int MESSAGE_MAX_LENGTH = 300;

    /**
     * Sans suite, une demande est supprimée ce nombre de jours après la
     * dernière (DataPurger).
     */
    public const int RETENTION_IN_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Normalisé comme l'email d'un compte (EmailNormalizer).
     */
    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: self::MESSAGE_MAX_LENGTH, nullable: true)]
    private ?string $message;

    /**
     * Date de la dernière demande : une nouvelle demande la repousse, et la
     * durée de conservation court à partir d'elle.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    public function __construct(string $email, ?string $message, \DateTimeImmutable $requestedAt)
    {
        $this->email = EmailNormalizer::normalize($email);
        $this->message = $this->normalizeMessage($message);
        $this->requestedAt = $requestedAt;
    }

    /**
     * Nouvelle demande de la même adresse : elle remplace la précédente.
     */
    public function renew(?string $message, \DateTimeImmutable $requestedAt): void
    {
        $this->message = $this->normalizeMessage($message);
        $this->requestedAt = $requestedAt;
    }

    /**
     * Sans espaces autour ; un message vide n'en est pas un.
     */
    private function normalizeMessage(?string $message): ?string
    {
        $message = trim($message ?? '');

        return '' === $message ? null : $message;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }
}
