<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordRequestType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Mot de passe oublié : demande d'un lien par email, puis choix d'un nouveau
 * mot de passe depuis ce lien. Pages publiques.
 */
#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        MailerInterface $mailer,
        #[Target('reset_password_request')]
        RateLimiterFactoryInterface $resetPasswordRequestLimiter,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Chaque demande valide compte, que le compte existe ou non. Au-delà
            // de la limite, ni recherche du compte ni mail.
            if ($resetPasswordRequestLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
                /** @var string $email */
                $email = $form->get('email')->getData();

                return $this->sendResetEmail($userRepository->loadUserByIdentifier($email), $mailer);
            }

            $form->addError(new FormError('Trop de demandes. Réessayez plus tard.'));
        }

        return $this->render('reset_password/request.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Même page que le compte existe ou non.
     */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            // Jeton factice sans compte : ne rien révéler.
            'resetToken' => $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken(),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator,
        ClockInterface $clock,
        ?string $token = null,
    ): Response {
        if (null !== $token) {
            // Le jeton passe en session et quitte l'URL : il ne fuit pas vers
            // l'historique ni vers un script tiers.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('Aucun jeton de réinitialisation dans l\'URL ni en session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $resetPasswordException) {
            $this->addFlash('error', $translator->trans($resetPasswordException->getReason(), [], 'ResetPasswordBundle'));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Un lien ne sert qu'une fois.
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $newPassword */
            $newPassword = $form->get('newPassword')->getData();
            // Un compte en attente s'active ici : sa période d'activité commence.
            if ($user->isPending()) {
                $user->recordLogin($clock->now());
            }

            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Votre mot de passe a été modifié, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('reset_password/reset.html.twig', [
            'form' => $form,
        ]);
    }

    private function sendResetEmail(?User $user, MailerInterface $mailer): RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_check_email', status: Response::HTTP_SEE_OTHER);
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop récente : même page, sans révéler que le compte existe.
            return $this->redirectToRoute('app_check_email', status: Response::HTTP_SEE_OTHER);
        }

        $mailer->send(new TemplatedEmail()
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken])
        );

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email', status: Response::HTTP_SEE_OTHER);
    }
}
