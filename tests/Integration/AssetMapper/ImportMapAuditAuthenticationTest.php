<?php

declare(strict_types=1);

namespace App\Tests\Integration\AssetMapper;

use App\Tests\Support\ContainerServiceTrait;
use App\Tests\Support\GithubApiMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anonyme, importmap:audit partage la limite de l'API GitHub de l'adresse IP
 * du runner : il s'authentifie avec GITHUB_ADVISORIES_TOKEN, envoyé à la
 * seule API GitHub.
 */
final class ImportMapAuditAuthenticationTest extends KernelTestCase
{
    use ContainerServiceTrait;

    public function testTheAuditSendsTheTokenToTheGithubApi(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('importmap:audit'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $requests = $this->service(GithubApiMock::class)->requests();
        self::assertCount(1, $requests);
        self::assertStringStartsWith('https://api.github.com/advisories?', $requests[0]['url']);
        self::assertSame('Authorization: Bearer '.GithubApiMock::TOKEN, $requests[0]['authorization']);
    }

    public function testTheTokenIsNotSentToJsdelivr(): void
    {
        $github = $this->service(GithubApiMock::class);
        $github->goOffline();

        $client = self::getContainer()->get('asset_mapper.http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);

        $client->request('GET', 'https://cdn.jsdelivr.net/npm/tom-select@2.4.3/+esm')->getStatusCode();

        $requests = $github->requests();
        self::assertCount(1, $requests);
        self::assertSame('https://cdn.jsdelivr.net/npm/tom-select@2.4.3/+esm', $requests[0]['url']);
        self::assertNull($requests[0]['authorization']);
    }
}
