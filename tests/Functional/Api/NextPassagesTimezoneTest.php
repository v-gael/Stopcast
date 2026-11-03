<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Factory\UserFactory;
use App\Tests\Support\RealtimeFeedTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Les heures de passage sont des instants POSIX du flux, sérialisés avec le
 * décalage de Paris (frankenphp/conf.d/10-timezone.ini).
 */
final class NextPassagesTimezoneTest extends WebTestCase
{
    use Factories;
    use RealtimeFeedTrait;
    use ResetDatabase;

    #[DataProvider('departures')]
    public function testPassageTimeCarriesTheParisOffset(int $timestamp, string $expectedAt): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $device = new Device(UserFactory::createOne(), 'Écran test');
        $configuration = new DisplayConfiguration($device, $quay)->setLabel('Centre');
        $device->addDisplayConfiguration($configuration);
        $entityManager->persist(new Route('R1', '1', 'FF5500', 'FFFFFF'));
        $entityManager->persist($quay);
        $entityManager->persist($device);
        $entityManager->persist($configuration);
        $entityManager->flush();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(self::feedResponse([
            new FeedEntity()->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId('T1')->setRouteId('R1'))
                ->setStopTimeUpdate([new StopTimeUpdate()->setStopId('S1')->setDeparture(new StopTimeEvent()->setTime($timestamp))])),
        ])));
        // Horloge dans le fuseau par défaut, comme l'horloge réelle, cinq minutes avant le passage.
        $container->set('clock', new MockClock(new \DateTimeImmutable()->setTimestamp($timestamp - 300)));

        $client->request('GET', '/api/next-passages', server: ['HTTP_X_DEVICE_TOKEN' => $device->getToken()]);

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        $displays = $response['displays'] ?? null;
        self::assertIsArray($displays);
        $display = $displays[0] ?? null;
        self::assertIsArray($display);
        $passages = $display['nextPassages'] ?? null;
        self::assertIsArray($passages);
        $passage = $passages[0] ?? null;
        self::assertIsArray($passage);
        self::assertSame($expectedAt, $passage['expectedAt'] ?? null);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function departures(): iterable
    {
        // 2027-01-15 07:00:00 UTC
        yield 'hiver' => [1799996400, '2027-01-15T08:00:00+01:00'];
        // 2027-07-15 06:00:00 UTC
        yield 'été' => [1815631200, '2027-07-15T08:00:00+02:00'];
    }
}
