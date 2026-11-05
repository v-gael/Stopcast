<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Supprime définitivement un compte, puis prévient son adresse par email,
 * sauf pour un compte en attente : c'est alors une invitation annulée.
 * Les dispositifs, leurs configurations et les demandes de réinitialisation
 * suivent par les clés étrangères en cascade.
 */
final readonly class AccountDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private DeletionJournal $journal,
    ) {
    }

    public function delete(User $user): void
    {
        $email = $user->getEmail();
        $wasPending = $user->isPending();

        // Avant la suppression : un compte ne disparaît jamais sans trace.
        // Si le flush échoue ensuite, le rejeu supprimera un compte dont la
        // suppression avait bien été demandée.
        $this->journal->record($user);

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        if ($wasPending) {
            return;
        }

        // Le worker envoie l'email après la suppression : il ne porte que l'adresse.
        $this->mailer->send(new TemplatedEmail()
            ->to($email)
            ->subject('Votre compte Stopcast a été supprimé')
            ->htmlTemplate('email/account_deleted.html.twig')
            ->textTemplate('email/account_deleted.txt.twig')
        );
    }

    /**
     * Rejoue une suppression du journal après une restauration : la personne
     * a déjà reçu son email, et la ligne est déjà au journal.
     */
    public function replay(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
