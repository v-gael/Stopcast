<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\Pairing\QrCodeSvg;
use App\Display\Pairing\ScreenPairing;
use App\Display\ShowcaseScreens;
use App\Enum\ShowcaseScreenMode;
use App\EventListener\SameOriginFramingListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Écran de la vitrine : la page d'affichage sur le jeu de démo
 * (ShowcaseScreens), publique et affichable dans une iframe du site. La page
 * d'accueil en montre plusieurs, chacun dans son cadre et à son format.
 */
final class ShowcaseController extends AbstractController
{
    /** Code fictif de l'écran d'appairage du parcours. */
    private const string PAIRING_CODE = 'K7F-3QX';

    public function __construct(
        private readonly ShowcaseScreens $showcaseScreens,
        private readonly QrCodeSvg $qrCodeSvg,
    ) {
    }

    #[Route('/vitrine/ecran', name: 'app_showcase_screen', defaults: [SameOriginFramingListener::ATTRIBUTE => true], methods: ['GET'])]
    public function screen(Request $request, #[MapQueryParameter] ShowcaseScreenMode $mode = ShowcaseScreenMode::Rotation): Response
    {
        // Le code est fictif : le QR code mène à la vitrine.
        $homeUrl = $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('showcase/screen.html.twig', [
            'mode' => $mode,
            'screens' => $this->showcaseScreens->all(),
            'now' => $this->showcaseScreens->now(),
            // Pour _screens.html.twig : durée d'un écran ; l'écran de la vitrine ne relit rien.
            'device' => ['screenDuration' => 4, 'refreshInterval' => 0],
            'code' => self::PAIRING_CODE,
            'remaining_seconds' => ScreenPairing::CODE_DISPLAY_SECONDS,
            'pairing_url' => $homeUrl,
            'qr_code' => $this->qrCodeSvg->render($homeUrl),
            'host' => $request->getHttpHost(),
        ]);
    }
}
