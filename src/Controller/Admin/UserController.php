<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Account\UserInviter;
use App\Entity\User;
use App\Form\InviteUserType;
use App\Form\UserRoleType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/admin/users')]
final class UserController extends AbstractController
{
    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'rows' => $userRepository->findAllWithDeviceCount(),
        ]);
    }

    #[Route('/invite', name: 'app_admin_user_invite', methods: ['GET', 'POST'])]
    public function invite(Request $request, UserInviter $userInviter): Response
    {
        $form = $this->createForm(InviteUserType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();
            $userInviter->invite($user);

            $this->addFlash('success', \sprintf('Invitation envoyée à %s.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/invite.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserRoleType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', \sprintf('Rôle de %s modifié.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
