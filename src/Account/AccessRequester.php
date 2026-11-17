<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Demandes d'accès des visiteurs : les enregistre et les signale par mail aux
 * administrateurs, puis les transforme en invitation. Rien ne part vers
 * l'adresse saisie avant l'invitation : le formulaire ne sert pas à écrire à
 * un tiers. Le visiteur reçoit la même réponse dans tous les cas, sans savoir
 * si un compte existe.
 */
final readonly class AccessRequester
{
    public function __construct(
        private AccessRequestRepository $accessRequestRepository,
        private UserRepository $userRepository,
        private UserInviter $userInviter,
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
        if ($this->hasAccount($email)) {
            return;
        }

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
            // enregistrée et a prévenu les administrateurs. Le gestionnaire
            // d'entités est fermé : plus rien ne s'écrit dans cette requête.
            return;
        }

        $this->notifyAdmins($accessRequest);
    }

    /**
     * Invite l'adresse de la demande, avec le rôle Utilisateur ; l'invitation
     * supprime la demande. Si un compte a été créé pour cette adresse depuis
     * la demande (changement d'email, commande), la demande est seulement
     * supprimée.
     *
     * @return bool false si un compte existait déjà
     */
    public function invite(AccessRequest $accessRequest): bool
    {
        if ($this->hasAccount($accessRequest->getEmail())) {
            $this->entityManager->remove($accessRequest);
            $this->entityManager->flush();

            return false;
        }

        $this->userInviter->invite(new User($accessRequest->getEmail()));

        return true;
    }

    private function hasAccount(string $email): bool
    {
        return $this->userRepository->loadUserByIdentifier($email) instanceof User;
    }

    /**
     * Un mail par administrateur : aucun ne voit l'adresse des autres.
     */
    private function notifyAdmins(AccessRequest $accessRequest): void
    {
        foreach ($this->userRepository->findActiveAdminEmails() as $adminEmail) {
            $this->mailer->send(new TemplatedEmail()
                ->to($adminEmail)
                ->subject(\sprintf("Demande d'accès de %s", $accessRequest->getEmail()))
                ->htmlTemplate('email/access_request.html.twig')
                ->textTemplate('email/access_request.txt.twig')
                ->context(['accessRequest' => $accessRequest])
            );
        }
    }
}
