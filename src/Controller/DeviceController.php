<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\Pairing\PairingCode;
use App\Display\Pairing\PairingConfirmation;
use App\Display\Pairing\ScreenPairing;
use App\Entity\Device;
use App\Entity\User;
use App\Form\DeviceType;
use App\Form\PairScreenType;
use App\Form\ScannedPairingType;
use App\Repository\DeviceRepository;
use App\Security\Voter\DeviceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /**
     * « pair » porte le code d'un écran scanné par un compte sans dispositif :
     * la création revient alors sur le choix du dispositif pour ce code. Ce
     * n'est jamais une adresse de retour ; un code invalide est ignoré.
     */
    #[Route('/new', name: 'app_device_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        #[MapQueryParameter] string $pair = '',
    ): Response {
        $device = new Device($user, '');
        $form = $this->createForm(DeviceType::class, $device);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($device);
            $entityManager->flush();

            $this->addFlash('success', 'Le dispositif a été créé.');
            $pairingCode = PairingCode::normalize($pair);

            if (null !== $pairingCode) {
                return $this->redirectToRoute('app_device_pair_scanned', ['code' => $pairingCode, 'device' => $device->getId()], Response::HTTP_SEE_OTHER);
            }

            return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('device/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Page ouverte en scannant le QR code d'un écran : choix du dispositif,
     * puis le même appairage que la saisie du code sur la fiche. Le code n'est
     * vérifié qu'à l'envoi : ouvrir l'adresse n'apprend rien sur sa validité.
     * « device » présélectionne un dispositif, comme après sa création.
     */
    #[Route('/pair', name: 'app_device_pair_scanned', methods: ['GET', 'POST'])]
    public function pairScanned(
        Request $request,
        #[CurrentUser] User $user,
        DeviceRepository $deviceRepository,
        ScreenPairing $screenPairing,
        #[MapQueryParameter] string $code = '',
        #[MapQueryParameter('device')] ?int $deviceId = null,
    ): Response {
        $pairingCode = PairingCode::normalize($code);

        if (null === $pairingCode) {
            return $this->render('device/pair_scanned.html.twig', ['code' => null, 'form' => null]);
        }

        $devices = $deviceRepository->findBy(['owner' => $user], ['label' => 'ASC', 'id' => 'ASC']);
        $preselected = array_find($devices, static fn (Device $candidate): bool => $candidate->getId() === $deviceId)
            ?? (1 === \count($devices) ? $devices[0] : null);

        $form = $this->createForm(ScannedPairingType::class, ['device' => $preselected], ['devices' => $devices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $chosen = $form->get('device')->getData();
            \assert($chosen instanceof Device);
            $this->denyAccessUnlessGranted(DeviceVoter::EDIT, $chosen);

            $error = $this->pairingError(
                $screenPairing->confirm($pairingCode, $chosen),
                "Ce code est inconnu ou a expiré. Scannez le nouveau code affiché sur l'écran.",
            );

            if (null === $error) {
                return $this->redirectToPairedDevice($chosen);
            }

            $form->addError(new FormError($error));
        }

        return $this->render('device/pair_scanned.html.twig', [
            'code' => PairingCode::format($pairingCode),
            'raw_code' => $pairingCode,
            'form' => [] === $devices ? null : $form,
        ]);
    }

    #[Route('/{id}', name: 'app_device_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(DeviceVoter::VIEW, 'device')]
    public function show(Device $device): Response
    {
        return $this->render('device/show.html.twig', [
            'device' => $device,
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

            $error = $this->pairingError(
                $screenPairing->confirm($code, $device),
                "Ce code est inconnu ou a expiré. Saisissez le code affiché en ce moment sur l'écran.",
            );

            if (null === $error) {
                return $this->redirectToPairedDevice($device);
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

    /**
     * Message d'erreur d'un appairage refusé, null s'il est accepté.
     */
    private function pairingError(PairingConfirmation $confirmation, string $unknownCodeMessage): ?string
    {
        return match ($confirmation) {
            PairingConfirmation::Confirmed => null,
            PairingConfirmation::UnknownCode => $unknownCodeMessage,
            PairingConfirmation::TooManyAttempts => "Trop d'essais. Réessayez plus tard.",
        };
    }

    private function redirectToPairedDevice(Device $device): Response
    {
        $this->addFlash('success', \sprintf("Code accepté : l'écran affichera « %s » dans quelques secondes.", $device->getLabel()));

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()], Response::HTTP_SEE_OTHER);
    }
}
