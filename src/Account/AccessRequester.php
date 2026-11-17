<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Enregistre la demande d'accès d'un visiteur et la signale par mail aux
 * administrateurs. Rien ne part vers l'adresse saisie : le formulaire ne sert
 * pas à écrire à un tiers. Le visiteur reçoit la même réponse dans tous les
 * cas, sans savoir si un compte existe.
 */
final readonly class AccessRequester
{
    public function __construct(
        private AccessRequestRepository $accessRequestRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Une adresse qui a déjà un compte est ignorée ; une adresse qui a déjà
     * une demande la met à jour.
     */
    public function request(string $email, ?string $message): void
    {
        if ($this->userRepository->loadUserByIdentifier($email) instanceof \App\Entity\User) {
            return;
        }

        $message = null === $message || '' === trim($message) ? null : trim($message);
        $now = $this->clock->now();

        $accessRequest = $this->accessRequestRepository->findOneByEmail($email);
        if (!$accessRequest instanceof AccessRequest) {
            $accessRequest = new AccessRequest($email, $message, $now);
            $this->entityManager->persist($accessRequest);
        } else {
            $accessRequest->renew($message, $now);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux demandes simultanées de la même adresse : l'autre est
            // enregistrée et a prévenu les administrateurs.
            return;
        }

        $this->notifyAdmins($accessRequest);
    }

    private function notifyAdmins(AccessRequest $accessRequest): void
    {
        $admins = $this->userRepository->findActiveAdminEmails();
        if ([] === $admins) {
            return;
        }

        $this->mailer->send(new TemplatedEmail()
            ->to(...$admins)
            ->subject(\sprintf("Demande d'accès de %s", $accessRequest->getEmail()))
            ->htmlTemplate('email/access_request.html.twig')
            ->textTemplate('email/access_request.txt.twig')
            ->context(['accessRequest' => $accessRequest])
        );
    }
}
