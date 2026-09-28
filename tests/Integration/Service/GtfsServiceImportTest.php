<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Quay;
use App\Entity\Route;
use App\Service\GtfsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GtfsServiceImportTest extends KernelTestCase
{
    use ResetDatabase;

    public function testReimportReplacesRoutesAndDestinationsOfEachQuay(): void
    {
        $this->importFeed($this->fixturesPath());
        $this->importFeed($this->fixturesPath('ServiceChanged'));

        $s1 = $this->findQuay('S1');
        self::assertSame(['R2'], $this->gtfsRouteIdsOf($s1));
        self::assertSame(['GARE SUD'], $s1->getDestinationsServed());

        $s2 = $this->findQuay('S2');
        self::assertSame([], $this->gtfsRouteIdsOf($s2));
        self::assertNull($s2->getDestinationsServed());
    }

    private function importFeed(string $fixturesPath): void
    {
        $gtfsService = new GtfsService(
            httpClient: new MockHttpClient(new MockResponse($this->zip($fixturesPath))),
            entityManager: $this->entityManager(),
            filesystem: new Filesystem(),
            gtfsStaticFeedUrl: 'https://example.test/gtfs-static.zip',
        );

        $gtfsService->importStaticFeed();
        $this->entityManager()->clear();
    }

    private function findQuay(string $gtfsStopId): Quay
    {
        $quay = $this->entityManager()->getRepository(Quay::class)->findOneBy(['gtfsStopId' => $gtfsStopId]);
        self::assertInstanceOf(Quay::class, $quay);

        return $quay;
    }

    /**
     * @return string[]
     */
    private function gtfsRouteIdsOf(Quay $quay): array
    {
        $ids = $quay->getRoutes()->map(static fn (Route $route): string => $route->getGtfsRouteId())->toArray();
        sort($ids);

        return $ids;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function zip(string $fixturesPath): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (glob($fixturesPath.'/*.txt') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    private function fixturesPath(?string $case = null): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/Gtfs'.(null !== $case ? '/'.$case : '');
    }
}
