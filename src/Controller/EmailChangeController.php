<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\EmailChanger;
use App\Account\PendingEmailChange;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation d'un changement d'email, depuis le lien reçu à la nouvelle
 * adresse. Page publique : le lien peut s'ouvrir sur un autre appareil. Seul
 * le POST applique le changement, les scanners de liens des messageries
 * s'arrêtant au GET.
 */
#[Route('/email-change')]
final class EmailChangeController extends AbstractController
{
    #[Route('/{token}', name: 'app_email_change_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request, string $token, EmailChanger $emailChanger): Response
    {
        $change = $emailChanger->pendingChange($request->getUri(), $token);
        if (!$change instanceof PendingEmailChange) {
            return $this->invalidLink();
        }

        $form = $this->createForm(FormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Avant le changement : la session se charge en comparant l'email.
            $currentUser = $this->getUser();
            $isOwnSession = $currentUser instanceof User && $change->user->isSameAccountAs($currentUser);

            if (!$emailChanger->confirm($change)) {
                return $this->invalidLink();
            }

            if ($isOwnSession) {
                $this->addFlash('success', \sprintf('Votre adresse est maintenant %s.', $change->newEmail));

                return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', \sprintf('Votre adresse est maintenant %s, connectez-vous avec elle.', $change->newEmail));

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('email_change/confirm.html.twig', [
            'form' => $form,
            'newEmail' => $change->newEmail,
        ]);
    }

    private function invalidLink(): RedirectResponse
    {
        $this->addFlash('error', "Ce lien de confirmation n'est plus valable.");

        return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
    }
}
