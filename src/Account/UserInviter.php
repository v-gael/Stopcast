<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;

/**
 * Crée un compte en attente et envoie à l'invité le lien pour choisir son
 * mot de passe. Le lien est une demande de réinitialisation du bundle, plus
 * longue que celle de « Mot de passe oublié ».
 */
final readonly class UserInviter
{
    public const int INVITATION_LIFETIME = 86400;

    public const int INVITATION_LIFETIME_IN_HOURS = self::INVITATION_LIFETIME / 3600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        // La classe plutôt que l'interface : seule elle accepte une durée par lien (l'interface l'aura en 2.0).
        #[Autowire(service: 'symfonycasts.reset_password.helper')]
        private ResetPasswordHelper $resetPasswordHelper,
        private MailerInterface $mailer,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
    ) {
    }

    public function invite(User $user): void
    {
        $this->assertPending($user);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->sendInvitation($user);
    }

    /**
     * Nouveau lien pour un compte en attente. Supprimer ses demandes en cours
     * invalide l'ancien lien et lève le throttling du bundle (1 h).
     */
    public function resend(User $user): void
    {
        $this->assertPending($user);

        $this->resetPasswordRequestRepository->removeRequests($user);

        $this->sendInvitation($user);
    }

    private function assertPending(User $user): void
    {
        if (!$user->isPending()) {
            throw new \LogicException(\sprintf('Le compte %s a déjà un mot de passe : il n\'est plus en attente d\'invitation.', $user->getEmail()));
        }
    }

    private function sendInvitation(User $user): void
    {
        $invitationToken = $this->resetPasswordHelper->generateResetToken($user, self::INVITATION_LIFETIME);

        $this->mailer->send(new TemplatedEmail()
            ->to($user->getEmail())
            ->subject('Activez votre compte Stopcast')
            ->htmlTemplate('email/invitation.html.twig')
            ->textTemplate('email/invitation.txt.twig')
            ->context([
                'invitationToken' => $invitationToken,
                // « 24 heures » : la traduction du bundle dirait « 1 jour ».
                'lifetimeInHours' => self::INVITATION_LIFETIME_IN_HOURS,
            ])
        );
    }
}
