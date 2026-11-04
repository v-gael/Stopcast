<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\DisplayAssembler;
use App\Display\Pairing\DisplayCookies;
use App\Display\Pairing\PairedScreen;
use App\Display\Pairing\PairingCode;
use App\Display\Pairing\PairingThrottled;
use App\Display\Pairing\PendingPairing;
use App\Display\Pairing\ScreenPairing;
use App\Display\ScreenPaginator;
use App\EventListener\FramedPageToolbarListener;
use App\Gtfs\Realtime\InvalidGtfsRealtimeFeedException;
use App\Security\DeviceUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Page d'affichage servie à l'écran d'un dispositif, authentifié par son
 * cookie (firewall « display ») ; sans cookie, l'écran d'appairage.
 *
 * « ?fragment=1 » ne rend que les écrans : la page les relit ainsi à chaque
 * rafraîchissement. Un fragment sans écran authentifié répond 401 : la page se
 * recharge alors sur l'écran d'appairage.
 */
final class DisplayController extends AbstractController
{
    public function __construct(
        private readonly ScreenPairing $screenPairing,
        private readonly DisplayCookies $displayCookies,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/display', name: 'app_display', defaults: [FramedPageToolbarListener::ATTRIBUTE => true], methods: ['GET'])]
    public function show(
        Request $request,
        DisplayAssembler $displayAssembler,
        ScreenPaginator $screenPaginator,
        #[CurrentUser] ?UserInterface $user,
        #[MapQueryParameter] bool $fragment = false,
    ): Response {
        $screen = $user instanceof DeviceUser ? $user : null;

        if (!$screen instanceof DeviceUser) {
            return $fragment ? new Response(null, Response::HTTP_UNAUTHORIZED) : $this->pairingPage($request);
        }

        $device = $screen->device;
        $parameters = [
            'device' => $device,
            'screens' => [],
            'refresh_url' => $this->generateUrl('app_display', ['fragment' => 1]),
            'state_message' => null,
            'offline' => false,
        ];

        if (!$device->isActive()) {
            $parameters['state_message'] = 'Écran désactivé';
        } else {
            try {
                $parameters['screens'] = $screenPaginator->paginate($displayAssembler->forDevice($device));
            } catch (HttpClientExceptionInterface|InvalidGtfsRealtimeFeedException) {
                // Le rafraîchissement échoue : l'écran garde ce qu'il affichait.
                if ($fragment) {
                    return new Response(null, Response::HTTP_SERVICE_UNAVAILABLE);
                }

                // Premier chargement : page vide qui réessaie à la cadence du dispositif.
                $parameters['state_message'] = '';
                $parameters['offline'] = true;
            }
        }

        return $this->render($fragment ? 'display/_screens.html.twig' : 'display/page.html.twig', $parameters);
    }

    /**
     * Interrogation de l'écran d'appairage, toutes les quelques secondes : le
     * code à afficher (200), l'attente si trop de codes ont été demandés
     * (429), ou 205 quand l'écran est appairé (son cookie est posé, la page se
     * recharge sur l'affichage).
     */
    #[Route('/display/pairing', name: 'app_display_pairing', defaults: [FramedPageToolbarListener::ATTRIBUTE => true], methods: ['GET'])]
    public function pairing(Request $request, #[CurrentUser] ?UserInterface $user): Response
    {
        if ($user instanceof DeviceUser) {
            return new Response(null, Response::HTTP_RESET_CONTENT);
        }

        $state = $this->screenPairing->forScreen($this->displayCookies->pairingSecret($request), $request->getClientIp() ?? '');

        if ($state instanceof PairedScreen) {
            return $this->paired($state, new Response(null, Response::HTTP_RESET_CONTENT));
        }

        return $this->renderPairing('display/_pairing.html.twig', $request, $state);
    }

    private function pairingPage(Request $request): Response
    {
        $state = $this->screenPairing->forScreen($this->displayCookies->pairingSecret($request), $request->getClientIp() ?? '');

        if ($state instanceof PairedScreen) {
            return $this->paired($state, $this->redirectToRoute('app_display', status: Response::HTTP_SEE_OTHER));
        }

        return $this->renderPairing('display/pairing.html.twig', $request, $state);
    }

    private function renderPairing(string $template, Request $request, PendingPairing|PairingThrottled $state): Response
    {
        $pending = $state instanceof PendingPairing ? $state : null;

        $response = $this->render($template, [
            'code' => $pending instanceof PendingPairing ? PairingCode::format($pending->code) : null,
            'remaining_seconds' => $pending instanceof PendingPairing ? max(0, $pending->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) : null,
            'host' => $request->getHttpHost(),
        ], new Response(status: $pending instanceof PendingPairing ? Response::HTTP_OK : Response::HTTP_TOO_MANY_REQUESTS));

        if ($pending instanceof PendingPairing) {
            $this->displayCookies->setPairing($response, $pending->requestSecret);
        }

        return $response;
    }

    private function paired(PairedScreen $paired, Response $response): Response
    {
        $this->displayCookies->setScreen($response, $paired->screenSecret, $this->clock->now());
        $this->displayCookies->clearPairing($response);

        return $response;
    }
}
