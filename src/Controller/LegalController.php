<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales : des pages statiques, publiques.
 */
final class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_legal_notice', methods: ['GET'])]
    public function notice(): Response
    {
        return $this->render('legal/notice.html.twig');
    }

    #[Route('/conditions-utilisation', name: 'app_legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }
}
