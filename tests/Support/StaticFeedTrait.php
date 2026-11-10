<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gtfs\Static\StaticFeedReader;
use App\Gtfs\Static\StaticReferenceImporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Import du référentiel statique branché sur un serveur de flux simulé, avec
 * les fixtures GTFS de tests/Fixtures/Gtfs.
 */
trait StaticFeedTrait
{
    private const string STATIC_FEED_URL = 'https://example.test/gtfs-static.zip';

    private function staticReferenceImporter(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        ?CacheItemPoolInterface $cache = null,
        ?LoggerInterface $logger = null,
        string $feedUrl = self::STATIC_FEED_URL,
    ): StaticReferenceImporter {
        return new StaticReferenceImporter(
            httpClient: $httpClient,
            entityManager: $entityManager,
            filesystem: new Filesystem(),
            reader: new StaticFeedReader(),
            cache: $cache ?? new ArrayAdapter(),
            logger: $logger ?? new NullLogger(),
            feedUrl: $feedUrl,
        );
    }

    /**
     * Archive d'un jeu de fixtures (null : le jeu par défaut), avec les validateurs donnés.
     */
    private function staticFeedResponse(?string $etag = null, ?string $lastModified = null, ?string $case = null): MockResponse
    {
        $headers = [];
        if (null !== $etag) {
            $headers['ETag'] = $etag;
        }

        if (null !== $lastModified) {
            $headers['Last-Modified'] = $lastModified;
        }

        return new MockResponse($this->staticFeedArchive($case), ['response_headers' => $headers]);
    }

    private function staticFeedArchive(?string $case = null): string
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (glob($this->staticFeedFixturesPath($case).'/*.txt') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    private function staticFeedFixturesPath(?string $case = null): string
    {
        return __DIR__.'/../Fixtures/Gtfs'.(null !== $case ? '/'.$case : '');
    }
}
