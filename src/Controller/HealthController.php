<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * État de l'application pour le healthcheck Docker, bin/smoke-test et la
 * sonde d'uptime : public, il ne vérifie que la base et ne dit rien d'autre.
 */
final class HealthController extends AbstractController
{
    // stateless : aucune session, donc aucun cookie, pour une URL appelée toutes les 30 s.
    #[Route('/health', name: 'app_health', methods: ['GET'], stateless: true)]
    public function __invoke(Connection $connection, LoggerInterface $logger): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $dbalException) {
            // Seulement une erreur de base : un bug garde sa page 500. Le détail
            // reste dans les logs, jamais dans la réponse publique.
            $logger->error('Health check: the database is unreachable.', ['exception' => $dbalException]);

            return $this->status('error', JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->status('ok', JsonResponse::HTTP_OK);
    }

    private function status(string $status, int $code): JsonResponse
    {
        $response = new JsonResponse(['status' => $status], $code);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
