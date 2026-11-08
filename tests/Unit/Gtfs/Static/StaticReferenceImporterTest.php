<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gtfs\Static;

use App\Gtfs\Static\InvalidGtfsFeedException;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Tests\Support\StaticFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Échecs survenus avant la transaction : l'entity manager n'est jamais sollicité.
 */
final class StaticReferenceImporterTest extends TestCase
{
    use StaticFeedTrait;

    public function testCorruptedArchiveLeavesNoTemporaryFileBehind(): void
    {
        $importer = $this->importer(new MockResponse('not a zip archive'));
        $tempEntriesBefore = $this->tempEntries();

        try {
            $importer->import();
            self::fail('Une archive corrompue doit lever InvalidGtfsFeedException.');
        } catch (InvalidGtfsFeedException) {
        }

        self::assertSame($tempEntriesBefore, $this->tempEntries());
    }

    public function testFailedDownloadLeavesNoTemporaryFileBehind(): void
    {
        $importer = $this->importer(new MockResponse('', ['http_code' => 500]));
        $tempEntriesBefore = $this->tempEntries();

        try {
            $importer->import();
            self::fail('Une erreur HTTP doit remonter.');
        } catch (ServerException) {
        }

        self::assertSame($tempEntriesBefore, $this->tempEntries());
    }

    private function importer(MockResponse $response): StaticReferenceImporter
    {
        return $this->staticReferenceImporter(new MockHttpClient($response), $this->createStub(EntityManagerInterface::class));
    }

    /**
     * @return string[]
     */
    private function tempEntries(): array
    {
        return glob(sys_get_temp_dir().'/gtfs_*') ?: [];
    }
}
