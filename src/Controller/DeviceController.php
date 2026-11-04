<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\Pairing\PairingConfirmation;
use App\Display\Pairing\ScreenPairing;
use App\Entity\Device;
use App\Entity\User;
use App\Form\DeviceType;
use App\Form\PairScreenType;
use App\Repository\DeviceRepository;
use App\Security\Voter\DeviceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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

    /**
     * Dispositifs par page, le bloc d'ajout complétant la grille de trois colonnes.
     */
    public const int PER_PAGE = 5;

    /**
     * La recherche ne sert qu'au-delà d'une page : en deçà, « q » est ignoré.
     * Une page au-delà de la dernière renvoie vers la dernière.
     */
    #[Route('', name: 'app_device_index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        DeviceRepository $deviceRepository,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] string $q = '',
    ): Response {
        $searchable = $deviceRepository->countByOwner($user) > self::PER_PAGE;
        $search = $searchable ? trim($q) : '';
        $page = max(1, $page);

        $devices = $deviceRepository->findPageByOwner($user, $search, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($devices) / self::PER_PAGE));

        if ($page > $pages) {
            return $this->redirectToRoute('app_device_index', array_filter(['page' => $pages, 'q' => $search]));
        }

        return $this->render('device/index.html.twig', [
            'devices' => $devices,
            'searchable' => $searchable,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
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

    /**
     * Saisie du code affiché par un écran : l'écran reçoit son cookie à sa
     * prochaine interrogation. Un écran déjà appairé est remplacé.
     */
    #[Route('/{id}/pair', name: 'app_device_pair', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(DeviceVoter::EDIT, 'device')]
    public function pair(Request $request, Device $device, ScreenPairing $screenPairing): Response
    {
        $form = $this->createForm(PairScreenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $code */
            $code = $form->get('code')->getData();

            $error = match ($screenPairing->confirm($code, $device)) {
                PairingConfirmation::Confirmed => null,
                PairingConfirmation::UnknownCode => "Ce code est inconnu ou a expiré. Saisissez le code affiché en ce moment sur l'écran.",
                PairingConfirmation::TooManyAttempts => "Trop d'essais. Réessayez plus tard.",
            };

            if (null === $error) {
                $this->addFlash('success', \sprintf("Code accepté : l'écran affichera « %s » dans quelques secondes.", $device->getLabel()));

                return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
            }

            $form->get('code')->addError(new FormError($error));
        }

        return $this->render('device/pair.html.twig', [
            'device' => $device,
            'form' => $form,
        ]);
    }

    /**
     * L'écran appairé perd son accès et revient à l'écran d'appairage.
     */
    #[Route('/{id}/unpair', name: 'app_device_unpair', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(DeviceVoter::EDIT, 'device')]
    #[IsCsrfTokenValid(new Expression('"unpair-device-" ~ args["device"].getId()'))]
    public function unpair(Device $device, EntityManagerInterface $entityManager): Response
    {
        $device->unpairScreen();
        $entityManager->flush();

        $this->addFlash('success', "L'écran a été dissocié : il affiche de nouveau un code d'appairage.");

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
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
