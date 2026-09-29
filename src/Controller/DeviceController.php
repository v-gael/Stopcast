<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\User;
use App\Form\DeviceType;
use App\Repository\DeviceRepository;
use App\Security\Voter\DeviceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/devices')]
final class DeviceController extends AbstractController
{
    /**
     * Jeton qui vient d'être créé ou régénéré, montré une seule fois sur le détail.
     */
    private const string NEW_TOKEN_FLASH = 'device_token';

    #[Route('', name: 'app_device_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, DeviceRepository $deviceRepository): Response
    {
        return $this->render('device/index.html.twig', [
            'devices' => $deviceRepository->findByOwner($user),
        ]);
    }

    #[Route('/new', name: 'app_device_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $device = new Device($user, '');
        $form = $this->createForm(DeviceType::class, $device);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($device);
            $entityManager->flush();

            $this->addFlash('success', 'Le dispositif a été créé.');

            return $this->redirectToShowWithNewToken($device);
        }

        return $this->render('device/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_device_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(DeviceVoter::VIEW, 'device')]
    public function show(Request $request, Device $device): Response
    {
        return $this->render('device/show.html.twig', [
            'device' => $device,
            'new_token' => $this->takeNewToken($request, $device),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_device_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(DeviceVoter::EDIT, 'device')]
    public function edit(Request $request, Device $device, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DeviceType::class, $device, ['include_active' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le dispositif a été modifié.');

            return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('device/edit.html.twig', [
            'device' => $device,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_device_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DeviceVoter::DELETE, 'device')]
    #[IsCsrfTokenValid(new Expression('"delete-device-" ~ args["device"].getId()'))]
    public function delete(Device $device, EntityManagerInterface $entityManager): Response
    {
        $entityManager->remove($device);
        $entityManager->flush();

        $this->addFlash('success', \sprintf('Le dispositif « %s » a été supprimé.', $device->getLabel()));

        return $this->redirectToRoute('app_device_index', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/regenerate-token', name: 'app_device_regenerate_token', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DeviceVoter::EDIT, 'device')]
    #[IsCsrfTokenValid(new Expression('"regenerate-device-token-" ~ args["device"].getId()'))]
    public function regenerateToken(Device $device, EntityManagerInterface $entityManager): Response
    {
        $device->regenerateToken();
        $entityManager->flush();

        $this->addFlash('success', 'Un nouveau jeton a été généré. L\'ancien ne fonctionne plus.');

        return $this->redirectToShowWithNewToken($device);
    }

    private function redirectToShowWithNewToken(Device $device): Response
    {
        $this->addFlash(self::NEW_TOKEN_FLASH, $device->getToken());

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Lire le flash le consomme : le jeton n'apparaît plus au rechargement. Il
     * n'est rendu que s'il est encore celui de ce dispositif.
     */
    private function takeNewToken(Request $request, Device $device): ?string
    {
        $session = $request->hasPreviousSession() ? $request->getSession() : null;

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return null;
        }

        $tokens = $session->getFlashBag()->get(self::NEW_TOKEN_FLASH);

        return \in_array($device->getToken(), $tokens, true) ? $device->getToken() : null;
    }
}
