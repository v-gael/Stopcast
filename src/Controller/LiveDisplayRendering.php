<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\DisplayPageState;
use App\Entity\Device;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rendu de la page d'affichage sur les passages réels, partagé par l'écran
 * (DisplayController) et l'aperçu de la page d'un dispositif
 * (DisplayPreviewController), pour qu'ils réagissent de la même façon.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
 */
trait LiveDisplayRendering
{
    /**
     * « $fragment » ne rend que les écrans. Un rafraîchissement dont le flux
     * échoue répond 503 : la page garde ce qu'elle affichait. Au premier
     * chargement, l'écran « indisponible » réessaie à la cadence du dispositif.
     */
    private function renderLiveDisplay(Device $device, DisplayPageState $state, bool $fragment, string $refreshUrl): Response
    {
        if ($fragment && $state->offline) {
            return new Response(null, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->render($fragment ? 'display/_screens.html.twig' : 'display/page.html.twig', [
            'device' => $device,
            'refresh_url' => $refreshUrl,
            ...$state->templateVariables(),
        ]);
    }
}
