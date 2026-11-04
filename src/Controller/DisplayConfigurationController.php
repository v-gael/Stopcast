<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DisplayConfiguration;
use App\Entity\User;
use App\Form\DisplayConfigurationType;
use App\Repository\DeviceRepository;
use App\Security\Voter\DisplayConfigurationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/display-configurations')]
final class DisplayConfigurationController extends AbstractController
{
    /**
     * « ?device={id} » présélectionne l'un de ses dispositifs ; celui d'un autre compte est ignoré.
     */
    #[Route('/new', name: 'app_display_configuration_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentUser] User $user,
        DeviceRepository $deviceRepository,
        EntityManagerInterface $entityManager,
        #[MapQueryParameter] ?int $device = null,
    ): Response {
        $form = $this->createForm(DisplayConfigurationType::class, options: ['owner' => $user]);

        if (null !== $device && !$request->isMethod('POST')) {
            $form->get('device')->setData($deviceRepository->findOneBy(['id' => $device, 'owner' => $user]));
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configuration = $form->getData();
            \assert($configuration instanceof DisplayConfiguration);
            $configuration->getDevice()->appendDisplayConfiguration($configuration);
            $entityManager->persist($configuration);
            $entityManager->flush();

            $this->addFlash('success', 'La configuration a été créée.');

            return $this->redirectToDevice($configuration);
        }

        return $this->render('display_configuration/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_display_configuration_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(DisplayConfigurationVoter::EDIT, 'configuration')]
    public function edit(Request $request, DisplayConfiguration $configuration, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DisplayConfigurationType::class, $configuration);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'La configuration a été modifiée.');

            return $this->redirectToDevice($configuration);
        }

        return $this->render('display_configuration/edit.html.twig', [
            'configuration' => $configuration,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_display_configuration_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DisplayConfigurationVoter::DELETE, 'configuration')]
    #[IsCsrfTokenValid(new Expression('"delete-display-configuration-" ~ args["configuration"].getId()'))]
    public function delete(DisplayConfiguration $configuration, EntityManagerInterface $entityManager): Response
    {
        $configuration->getDevice()->getDisplayConfigurations()->removeElement($configuration);
        $entityManager->remove($configuration);
        $entityManager->flush();

        $this->addFlash('success', \sprintf('La configuration « %s » a été supprimée.', $configuration->getLabel() ?: $configuration->getQuay()->getName()));

        return $this->redirectToDevice($configuration);
    }

    #[Route('/{id}/move-up', name: 'app_display_configuration_move_up', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DisplayConfigurationVoter::EDIT, 'configuration')]
    #[IsCsrfTokenValid(new Expression('"move-display-configuration-" ~ args["configuration"].getId()'))]
    public function moveUp(DisplayConfiguration $configuration, EntityManagerInterface $entityManager): Response
    {
        $configuration->getDevice()->moveDisplayConfigurationUp($configuration);
        $entityManager->flush();

        return $this->redirectToDevice($configuration);
    }

    #[Route('/{id}/move-down', name: 'app_display_configuration_move_down', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DisplayConfigurationVoter::EDIT, 'configuration')]
    #[IsCsrfTokenValid(new Expression('"move-display-configuration-" ~ args["configuration"].getId()'))]
    public function moveDown(DisplayConfiguration $configuration, EntityManagerInterface $entityManager): Response
    {
        $configuration->getDevice()->moveDisplayConfigurationDown($configuration);
        $entityManager->flush();

        return $this->redirectToDevice($configuration);
    }

    /**
     * Affiche (« active=1 ») ou masque (« active=0 ») la configuration : l'état demandé, pas une bascule,
     * pour qu'un double envoi ne la remette pas dans l'état d'avant.
     */
    #[Route('/{id}/visibility', name: 'app_display_configuration_visibility', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DisplayConfigurationVoter::EDIT, 'configuration')]
    #[IsCsrfTokenValid(new Expression('"display-configuration-visibility-" ~ args["configuration"].getId()'))]
    public function visibility(Request $request, DisplayConfiguration $configuration, EntityManagerInterface $entityManager): Response
    {
        $configuration->setActive($request->getPayload()->getBoolean('active'));
        $entityManager->flush();

        return $this->redirectToDevice($configuration);
    }

    private function redirectToDevice(DisplayConfiguration $configuration): Response
    {
        return $this->redirectToRoute('app_device_show', ['id' => $configuration->getDevice()->getId()], Response::HTTP_SEE_OTHER);
    }
}
