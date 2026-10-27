<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Device;
use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class NextPassagesProfilerTest extends WebTestCase
{
    use Factories;
    use RealtimeFeedTrait;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testNextPassagesCallIsNotProfiled(): void
    {
        $this->requestNextPassages('/api/next-passages');

        // null : la réponse ne porte pas de jeton de profil (false voudrait dire « pas de profiler »).
        self::assertNull($this->client->getProfile());
    }

    public function testRejectedNextPassagesCallIsNotProfiled(): void
    {
        $this->client->enableProfiler();
        $this->client->request('GET', '/api/next-passages');

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->client->getProfile());
    }

    public function testNextPassagesCallIsProfiledOnRequest(): void
    {
        $this->requestNextPassages('/api/next-passages?_profile=1');

        self::assertInstanceOf(Profile::class, $this->client->getProfile());
    }

    public function testAdminPageIsProfiled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['role' => Role::Admin]));
        $this->client->enableProfiler();

        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertInstanceOf(Profile::class, $this->client->getProfile());
    }

    private function requestNextPassages(string $uri): void
    {
        $device = new Device(UserFactory::createOne(), 'Écran test');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($device);
        $entityManager->flush();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(self::feedResponse([])));
        $container->set('clock', new MockClock(self::NOW));

        $this->client->enableProfiler();
        $this->client->request('GET', $uri, server: ['HTTP_X_DEVICE_TOKEN' => $device->getToken()]);

        self::assertResponseIsSuccessful();
    }
}
