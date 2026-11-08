<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales et sources des données : des pages statiques, publiques.
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

    #[Route('/confidentialite', name: 'app_legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    /**
     * Attribution des données GTFS exigée par leur licence (ODbL).
     */
    #[Route('/sources', name: 'app_data_sources', methods: ['GET'])]
    public function dataSources(): Response
    {
        return $this->render('legal/data_sources.html.twig');
    }
}
