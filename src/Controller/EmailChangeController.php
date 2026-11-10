<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation d'un changement d'email, depuis le lien reçu à la nouvelle
 * adresse. Page publique : le lien peut s'ouvrir sur un autre appareil.
 */
#[Route('/email-change')]
final class EmailChangeController extends AbstractController
{
    #[Route('/{token}', name: 'app_email_change_confirm', methods: ['GET', 'POST'])]
    public function confirm(): Response
    {
        return new Response();
    }
}
