<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\DisplayAssembler;
use App\Display\LiveDisplay;
use App\Display\ScreenPaginator;
use App\Dto\GtfsRt\DisplayItem;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\EventListener\SameOriginFramingListener;
use App\Security\Voter\DeviceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aperçu de la page d'affichage, réservé au propriétaire du dispositif et
 * affichable dans une iframe du site : sur les passages d'exemple pour la
 * liste des dispositifs et le formulaire d'une configuration, sur les passages
 * réels, comme l'écran, pour la page d'un dispositif.
 *
 * « ?fragment=1 » ne rend que les écrans : la page les relit ainsi à chaque
 * rafraîchissement, sans se recharger.
 */
final class DisplayPreviewController extends AbstractController
{
    public function __construct(
        private readonly DisplayAssembler $displayAssembler,
        private readonly ScreenPaginator $screenPaginator,
        private readonly LiveDisplay $liveDisplay,
    ) {
    }

    /**
     * Toute la rotation du dispositif sur les passages d'exemple : ses
     * configurations actives, sans les quais dépréciés.
     */
    #[Route('/devices/{id}/preview', name: 'app_device_preview', requirements: ['id' => Requirement::DIGITS], defaults: [SameOriginFramingListener::ATTRIBUTE => true], methods: ['GET'])]
    #[IsGranted(DeviceVoter::VIEW, 'device')]
    public function device(Device $device, #[MapQueryParameter] bool $fragment = false): Response
    {
        return $this->renderDisplay(
            $device,
            $this->displayAssembler->sampleForDevice($device),
            $fragment,
            $this->generateUrl('app_device_preview', ['id' => $device->getId(), 'fragment' => 1]),
        );
    }

    /**
     * Ce que montre l'écran du dispositif, sur les passages réels : écran
     * désactivé, flux en panne et quais dépréciés compris.
     */
    #[Route('/devices/{id}/preview/live', name: 'app_device_live_preview', requirements: ['id' => Requirement::DIGITS], defaults: [SameOriginFramingListener::ATTRIBUTE => true], methods: ['GET'])]
    #[IsGranted(DeviceVoter::VIEW, 'device')]
    public function liveDevice(Device $device, #[MapQueryParameter] bool $fragment = false): Response
    {
        $live = $this->liveDisplay->forDevice($device);

        // Comme l'écran : un rafraîchissement en échec garde ce qui est affiché.
        if ($fragment && $live->feedUnavailable) {
            return new Response(null, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->render($fragment ? 'display/_screens.html.twig' : 'display/page.html.twig', [
            'device' => $device,
            'screens' => $live->screens,
            'refresh_url' => $this->generateUrl('app_device_live_preview', ['id' => $device->getId(), 'fragment' => 1]),
            'state_message' => $live->stateMessage,
            'offline' => $live->feedUnavailable,
        ]);
    }

    /**
     * Une seule configuration sur les passages d'exemple, même masquée.
     */
    #[Route('/display-configurations/{id}/preview', name: 'app_display_configuration_preview', requirements: ['id' => Requirement::DIGITS], defaults: [SameOriginFramingListener::ATTRIBUTE => true], methods: ['GET'])]
    #[IsGranted(DeviceVoter::VIEW, new Expression('args["configuration"].getDevice()'))]
    public function configuration(DisplayConfiguration $configuration, #[MapQueryParameter] bool $fragment = false): Response
    {
        return $this->renderDisplay(
            $configuration->getDevice(),
            [$this->displayAssembler->sampleForConfiguration($configuration)],
            $fragment,
            $this->generateUrl('app_display_configuration_preview', ['id' => $configuration->getId(), 'fragment' => 1]),
        );
    }

    /**
     * @param list<DisplayItem> $items
     */
    private function renderDisplay(Device $device, array $items, bool $fragment, string $refreshUrl): Response
    {
        return $this->render($fragment ? 'display/_screens.html.twig' : 'display/page.html.twig', [
            'device' => $device,
            'screens' => $this->screenPaginator->paginate($items),
            'refresh_url' => $refreshUrl,
        ]);
    }
}
