<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Supprime définitivement un compte, puis prévient son adresse par email.
 * Les dispositifs, leurs configurations et les demandes de réinitialisation
 * suivent par les clés étrangères en cascade.
 */
final readonly class AccountDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
    ) {
    }

    public function delete(User $user): void
    {
        $email = $user->getEmail();

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // Le worker envoie l'email après la suppression : il ne porte que l'adresse.
        $this->mailer->send(new TemplatedEmail()
            ->to($email)
            ->subject('Votre compte Abribus a été supprimé')
            ->htmlTemplate('email/account_deleted.html.twig')
            ->textTemplate('email/account_deleted.txt.twig')
        );
    }
}
