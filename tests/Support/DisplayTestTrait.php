<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Gestes des tests de la page d'affichage (écran et aperçu), faits entre
 * deux requêtes du client de test.
 */
trait DisplayTestTrait
{
    /**
     * Le flux temps réel répond en erreur à chaque téléchargement.
     */
    private function failRealtimeFeed(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])));
    }

    private function disableDevice(Device $device): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $stored = $entityManager->find(Device::class, $device->getId());
        self::assertInstanceOf(Device::class, $stored);
        $stored->setActive(false);
        $entityManager->flush();
        $entityManager->clear();
    }
}
