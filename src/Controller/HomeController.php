<?php

declare(strict_types=1);

namespace App\Controller;

use App\Display\Pairing\QrCodeSvg;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class HomeController extends AbstractController
{
    use TargetPathTrait;

    /** Adresse pour laquelle le panneau de connexion s'est déjà ouvert seul. */
    private const string OPENED_FOR_TARGET_PATH = 'login_panel_opened_for';

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(Request $request, AuthenticationUtils $authenticationUtils, QrCodeSvg $qrCodeSvg): Response
    {
        // L'échec d'une connexion est rangé en session : un simple visiteur n'en a pas, ne pas en ouvrir une.
        $hasSession = $request->hasPreviousSession();

        $error = $hasSession ? $authenticationUtils->getLastAuthenticationError() : null;

        // Panneau de connexion de l'en-tête (layout/_login_panel.html.twig) : rouvert après un échec,
        // ou pour un visiteur renvoyé ici depuis une page de l'espace.
        return $this->render('home/index.html.twig', [
            'login_username' => $hasSession ? $authenticationUtils->getLastUsername() : '',
            'login_error' => $error,
            // QR code de la page d'appairage du parcours « Comment ça marche » : le code est fictif, il mène à la vitrine.
            'qr_code' => $qrCodeSvg->render($this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL)),
            'login_panel_open' => $error instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException || ($hasSession && $this->isFirstVisitAfterARedirectToLogin($request->getSession())),
        ]);
    }

    /**
     * Le firewall garde l'adresse demandée jusqu'à la connexion, pour y
     * renvoyer ensuite : le panneau ne s'ouvre seul qu'à la première visite
     * qui suit ce renvoi, pas à chaque retour sur la vitrine.
     */
    private function isFirstVisitAfterARedirectToLogin(SessionInterface $session): bool
    {
        $targetPath = $this->getTargetPath($session, 'main');

        if (null === $targetPath || $session->get(self::OPENED_FOR_TARGET_PATH) === $targetPath) {
            return false;
        }

        $session->set(self::OPENED_FOR_TARGET_PATH, $targetPath);

        return true;
    }
}
