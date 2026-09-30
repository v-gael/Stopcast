<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountDeleter;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\DeleteAccountType;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/account')]
final class AccountController extends AbstractController
{
    #[Route('', name: 'app_account', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('account/show.html.twig');
    }

    #[Route('/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        #[CurrentUser] User $user,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $newPassword */
            $newPassword = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/change_password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/delete', name: 'app_account_delete', methods: ['GET', 'POST'])]
    public function delete(
        Request $request,
        #[CurrentUser] User $user,
        DeviceRepository $deviceRepository,
        AccountDeleter $accountDeleter,
        Security $security,
    ): Response {
        $form = $this->createForm(DeleteAccountType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $accountDeleter->delete($user);

            // Invalide la session et efface le cookie « Se souvenir de moi ».
            $security->logout(false);

            // Dans la nouvelle session, ouverte après la déconnexion.
            $this->addFlash('success', 'Votre compte a été supprimé.');

            return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/delete.html.twig', [
            'form' => $form,
            'deviceCount' => $deviceRepository->count(['owner' => $user]),
        ]);
    }
}
