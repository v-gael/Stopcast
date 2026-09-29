<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // L'échec d'une connexion est rangé en session : un simple visiteur n'en a pas, ne pas en ouvrir une.
        $hasSession = $request->hasPreviousSession();

        return $this->render('home/index.html.twig', [
            'last_username' => $hasSession ? $authenticationUtils->getLastUsername() : '',
            'error' => $hasSession ? $authenticationUtils->getLastAuthenticationError() : null,
        ]);
    }
}
