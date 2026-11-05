<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\GtfsImportCommand;
use App\Gtfs\Static\StaticFeedReader;
use App\Gtfs\Static\StaticReferenceImporter;
use App\Monitoring\HealthcheckPinger;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GtfsImportCommandTest extends KernelTestCase
{
    use ResetDatabase;

    private const string FEED_URL = 'https://example.test/gtfs-static.zip';

    private const string PING_URL = 'https://hc-ping.test/gtfs';

    private ArrayAdapter $cache;

    private TestHandler $logs;

    /**
     * URL des pings envoyés.
     *
     * @var list<string>
     */
    private array $pings = [];

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->logs = new TestHandler();
        $this->pings = [];
    }

    public function testASuccessfulImportPingsHealthchecks(): void
    {
        $tester = $this->command(feed: $this->feedResponse());

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([self::PING_URL], $this->pings);
    }

    public function testAnUnchangedFeedIsSkippedLoggedAndPinged(): void
    {
        $this->command(feed: $this->feedResponse(etag: '"v1"'))->execute([]);
        $this->pings = [];

        $tester = $this->command(feed: new MockResponse('', ['http_code' => 304]));
        $tester->execute(['--if-changed' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Référentiel inchangé : import sauté.', $tester->getDisplay());
        self::assertTrue($this->logs->hasInfo('GTFS import: the feed is unchanged, import skipped.'));
        self::assertSame([self::PING_URL], $this->pings);
    }

    public function testASuccessfulImportLogsItsSummary(): void
    {
        $this->command(feed: $this->feedResponse())->execute([]);

        self::assertTrue($this->logs->hasInfoThatPasses(
            static fn (LogRecord $record): bool => 'GTFS import done: {summary}' === $record->message
                && \is_string($record->context['summary'] ?? null) && str_contains($record->context['summary'], 'créé(s)'),
        ));
    }

    public function testAnEmptyHealthcheckUrlSendsNoPing(): void
    {
        $tester = $this->command(feed: $this->feedResponse(), pingUrl: '');

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->pings);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function failedPings(): iterable
    {
        yield 'réponse en erreur' => [new MockResponse('', ['http_code' => 503])];
        yield 'Healthchecks injoignable' => [new MockResponse([new \RuntimeException('Délai dépassé')])];
    }

    #[DataProvider('failedPings')]
    public function testAFailedPingIsLoggedWithoutFailingTheImport(MockResponse $ping): void
    {
        $tester = $this->command(feed: $this->feedResponse(), ping: $ping);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->logs->hasWarning('Healthcheck ping failed: {error}'));
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function failures(): iterable
    {
        yield 'fichier introuvable' => [new MockResponse('', ['http_code' => 404]), 'HTTP 404'];
        yield 'erreur du serveur' => [new MockResponse('', ['http_code' => 500]), 'HTTP 500'];
        yield 'serveur injoignable' => [new MockResponse([new \RuntimeException('Connexion refusée')]), 'Connexion refusée'];
        yield 'archive illisible' => [new MockResponse('pas une archive'), 'Flux GTFS invalide'];
    }

    #[DataProvider('failures')]
    public function testAFailureIsReportedAndLoggedWithoutPing(MockResponse $feed, string $reason): void
    {
        $tester = $this->command(feed: $feed);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString($reason, $tester->getDisplay());
        self::assertTrue($this->logs->hasErrorThatPasses(
            static fn (LogRecord $record): bool => 'GTFS import failed: {error}' === $record->message
                && \is_string($record->context['error'] ?? null) && str_contains($record->context['error'], $reason),
        ));
        self::assertSame([], $this->pings);
    }

    /**
     * Commande branchée sur un serveur de flux simulé : $feed répond à
     * l'archive, $ping au ping (200 par défaut).
     */
    private function command(MockResponse $feed, ?MockResponse $ping = null, string $pingUrl = self::PING_URL): CommandTester
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($feed, $ping): MockResponse {
            if (self::FEED_URL === $url) {
                return $feed;
            }

            $this->pings[] = $url;

            return $ping ?? new MockResponse('OK');
        });
        $logger = new Logger('app', [$this->logs]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return new CommandTester(new GtfsImportCommand(
            new StaticReferenceImporter($httpClient, $entityManager, new Filesystem(), new StaticFeedReader(), $this->cache, $logger, self::FEED_URL),
            new HealthcheckPinger($httpClient, $logger),
            $logger,
            $pingUrl,
        ));
    }

    private function feedResponse(?string $etag = null): MockResponse
    {
        return new MockResponse($this->zip(), ['response_headers' => null !== $etag ? ['ETag' => $etag] : []]);
    }

    private function zip(): string
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (glob(\dirname(__DIR__, 2).'/Fixtures/Gtfs/*.txt') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }
}
