<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        #[Target('email_change_request')]
        private RateLimiterFactoryInterface $requestLimiter,
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
