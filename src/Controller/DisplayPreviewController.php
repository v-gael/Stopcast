<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\DisplayAssembler;
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
 * Aperçu de la page d'affichage sur les passages d'exemple, réservé au
 * propriétaire du dispositif et affichable dans une iframe du site.
 *
 * « ?fragment=1 » ne rend que les écrans : la page les relit ainsi à chaque
 * rafraîchissement, sans se recharger.
 */
final class DisplayPreviewController extends AbstractController
{
    public function __construct(
        private readonly DisplayAssembler $displayAssembler,
        private readonly ScreenPaginator $screenPaginator,
    ) {
    }

    /**
     * Toute la rotation du dispositif : ses configurations actives.
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
     * Une seule configuration, même masquée.
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
