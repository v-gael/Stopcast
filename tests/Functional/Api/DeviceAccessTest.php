<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DeviceAccessTest extends WebTestCase
{
    use Factories;
    use RealtimeFeedTrait;
    use ResetDatabase;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Device $device;

    private Quay $quay;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $this->device = new Device(UserFactory::createOne(), 'Écran test');

        $entityManager->persist(new Route('R1', '1', 'FF5500', 'FFFFFF'));
        $entityManager->persist($this->quay);
        $entityManager->persist($this->device);
        $entityManager->flush();
    }

    public function testDeviceReadsItsNextPassages(): void
    {
        $this->configure('Centre', position: 0);
        $this->serveRealtimeFeed([
            new FeedEntity()->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId('T1')->setRouteId('R1'))
                ->setStopTimeUpdate([new StopTimeUpdate()->setStopId('S1')->setDeparture(new StopTimeEvent()->setTime(self::at(5)))])),
        ]);

        $displays = $this->displaysFor($this->device->getToken());

        self::assertIsArray($displays[0] ?? null);
        $passages = $displays[0]['nextPassages'] ?? null;
        self::assertIsArray($passages);
        self::assertSame(['1'], array_column($passages, 'routeShortName'));
    }

    public function testDeviceReceivesItsActiveDisplaysInPositionOrder(): void
    {
        $this->configure('Troisième', position: 5);
        $this->configure('Masqué', position: 0, active: false);
        $this->configure('Premier', position: 1);
        $this->configure('Deuxième', position: 2);
        $this->serveRealtimeFeed([]);

        $displays = $this->displaysFor($this->device->getToken());

        self::assertSame(['Premier', 'Deuxième', 'Troisième'], array_column($displays, 'label'));
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->client->request('GET', '/api/next-passages');

        $this->assertDeviceRejected('Token manquant.');
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => 'jeton-inconnu']);

        $this->assertDeviceRejected('Token invalide ou dispositif désactivé.');
    }

    public function testDisabledDeviceIsRejected(): void
    {
        $this->device->setActive(false);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $this->device->getToken()]);

        $this->assertDeviceRejected('Token invalide ou dispositif désactivé.');
    }

    public function testUserSessionDoesNotOpenNextPassages(): void
    {
        // Vraie connexion par le formulaire : loginUser() injecterait le jeton quel que soit le firewall.
        $user = UserFactory::createOne(['role' => Role::Admin]);
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::DEFAULT_PASSWORD,
        ]));
        self::assertResponseRedirects('/devices');

        $this->client->request('GET', '/api/next-passages');

        $this->assertDeviceRejected('Token manquant.');
    }

    public function testDeviceTokenDoesNotOpenTheUserSpace(): void
    {
        $this->client->request('GET', '/account', server: ['HTTP_X_DEVICE_TOKEN' => $this->device->getToken()]);

        self::assertResponseRedirects('/');
    }

    private function configure(string $label, int $position, bool $active = true): void
    {
        $configuration = new DisplayConfiguration($this->device, $this->quay)
            ->setLabel($label)
            ->setPosition($position)
            ->setActive($active);
        $this->device->addDisplayConfiguration($configuration);
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
    }

    /**
     * @return array<mixed>
     */
    private function displaysFor(string $token): array
    {
        $this->client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $token]);

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        $displays = $response['displays'] ?? null;
        self::assertIsArray($displays);

        return $displays;
    }

    /**
     * Refus au format d'erreur de l'API, sans WWW-Authenticate : le jeton passe
     * par X-Device-Token, pas par un schéma d'authentification standard.
     */
    private function assertDeviceRejected(string $detail): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json; charset=utf-8');
        self::assertResponseNotHasHeader('WWW-Authenticate');

        $error = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($error);
        self::assertSame($detail, $error['detail'] ?? null);
    }

    /**
     * Le flux temps réel amont renvoie ces entités, à l'heure fixée.
     *
     * @param FeedEntity[] $entities
     */
    private function serveRealtimeFeed(array $entities): void
    {
        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(self::feedResponse($entities)));
        $container->set('clock', new MockClock(self::NOW, date_default_timezone_get()));
    }
}
