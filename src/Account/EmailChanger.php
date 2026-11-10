<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Logging\PersonalDataMasker;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Changement d'email d'un compte : une demande envoie à la nouvelle adresse
 * un lien signé, qui applique le changement une fois confirmé. Rien n'est
 * stocké : le lien porte tout ce qu'il faut pour se vérifier.
 */
final readonly class EmailChanger
{
    private const string LINK_LIFETIME = 'PT1H';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private MailerInterface $mailer,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        #[Target('email_change_request')]
        private RateLimiterFactoryInterface $requestLimiter,
        private PersonalDataMasker $personalDataMasker,
    ) {
    }

    /**
     * @return string la nouvelle adresse, normalisée
     *
     * @throws EmailChangeRefusedException
     */
    public function request(User $user, string $newEmail): string
    {
        // Avant les contrôles d'adresse : un compte connecté ne teste pas sans
        // limite quelles adresses ont un compte.
        if (!$this->requestLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            throw new EmailChangeRefusedException('Trop de demandes. Réessayez plus tard.');
        }

        $newEmail = EmailNormalizer::normalize($newEmail);
        if ($newEmail === $user->getEmail()) {
            throw new EmailChangeRefusedException("C'est déjà l'adresse de votre compte.");
        }

        if ($this->userRepository->loadUserByIdentifier($newEmail) instanceof User) {
            throw new EmailChangeRefusedException('Un compte existe déjà avec cet email.');
        }

        $this->mailer->send(new TemplatedEmail()
            ->to($newEmail)
            ->subject('Confirmez votre nouvelle adresse')
            ->htmlTemplate('email/email_change_request.html.twig')
            ->textTemplate('email/email_change_request.txt.twig')
            ->context(['link' => $this->link($user, $newEmail)])
        );

        return $newEmail;
    }

    /**
     * Le changement que porte ce lien, s'il est encore applicable : lien
     * intact et non expiré, compte toujours à l'adresse d'origine, nouvelle
     * adresse toujours libre.
     */
    public function pendingChange(Request $request, string $token): ?PendingEmailChange
    {
        if (!$this->uriSigner->checkRequest($request)) {
            return null;
        }

        /** @var array{int, string, string} $payload */
        $payload = json_decode((string) base64_decode(strtr($token, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        [$userId, $newEmail, $currentEmailFingerprint] = $payload;

        $user = $this->userRepository->find($userId);
        if (!$user instanceof User || self::fingerprint($user->getEmail()) !== $currentEmailFingerprint) {
            return null;
        }

        return $this->userRepository->loadUserByIdentifier($newEmail) instanceof User ? null : new PendingEmailChange($user, $newEmail);
    }

    /**
     * Applique le changement. Les liens de réinitialisation du mot de passe
     * déjà envoyés partent avec l'ancienne adresse : ils ne doivent pas
     * survivre à un changement fait parce que cette boîte est compromise.
     *
     * @return bool false si l'adresse vient d'être prise par une autre requête
     */
    public function confirm(PendingEmailChange $change): bool
    {
        $user = $change->user;
        $oldEmail = $user->getEmail();

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $change): void {
                $user->changeEmail($change->newEmail);
                $this->entityManager->flush();
                $this->resetPasswordRequestRepository->removeRequests($user);
            });
        } catch (UniqueConstraintViolationException) {
            // La session du compte s'enregistre encore à la fin de la requête.
            $user->changeEmail($oldEmail);

            return false;
        }

        $this->mailer->send(new TemplatedEmail()
            ->to($oldEmail)
            ->subject('Votre adresse a été modifiée')
            ->htmlTemplate('email/email_changed.html.twig')
            ->textTemplate('email/email_changed.txt.twig')
            ->context(['maskedNewEmail' => $this->personalDataMasker->mask($change->newEmail)])
        );

        return true;
    }

    private function link(User $user, string $newEmail): string
    {
        $url = $this->urlGenerator->generate('app_email_change_confirm', [
            'token' => $this->token($user, $newEmail),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, new \DateInterval(self::LINK_LIFETIME));
    }

    /**
     * L'empreinte de l'adresse actuelle rend le lien à usage unique, sans
     * montrer cette adresse à qui reçoit le lien.
     */
    private function token(User $user, string $newEmail): string
    {
        $payload = json_encode([$user->getId(), $newEmail, $this->fingerprint($user->getEmail())], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function fingerprint(string $email): string
    {
        return hash('sha256', $email);
    }
}
