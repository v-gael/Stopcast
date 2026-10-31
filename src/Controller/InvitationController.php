<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordType;
use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Choix du mot de passe depuis le lien d'invitation, qui active le compte.
 * Page publique. Le lien est une demande du bundle de réinitialisation : seul
 * l'état du compte distingue une invitation d'une réinitialisation.
 */
#[Route('/invitation')]
final class InvitationController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
    ) {
    }

    #[Route('/{token}', name: 'app_accept_invitation', methods: ['GET', 'POST'])]
    public function accept(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        ResetPasswordRequestRepository $resetPasswordRequestRepository,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        ?string $token = null,
    ): Response {
        if (null !== $token) {
            // Le jeton passe en session et quitte l'URL, comme pour la réinitialisation.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_accept_invitation');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException("Aucun jeton d'invitation dans l'URL ni en session.");
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->addFlash('error', "Ce lien d'invitation n'est plus valable. Demandez un nouveau lien ci-dessous.");

            return $this->redirectToRoute('app_forgot_password_request');
        }

        if (!$user->isPending()) {
            $this->resetPasswordHelper->removeResetRequest($token);
            $this->cleanSessionAfterReset();
            $this->addFlash('warning', 'Votre compte est déjà activé, connectez-vous.');

            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $password */
            $password = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $password));
            // L'activation ouvre la période d'activité du compte, sans session.
            $user->recordLogin($clock->now());
            $entityManager->flush();

            // Le compte est actif : aucun de ses liens ne doit encore servir.
            $resetPasswordRequestRepository->removeRequests($user);

            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Votre compte est activé, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('invitation/accept.html.twig', [
            'form' => $form,
        ]);
    }
}
