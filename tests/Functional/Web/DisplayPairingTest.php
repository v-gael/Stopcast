<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\RealtimeFeedTrait;
use App\Tests\Support\ScreenPairingTrait;
use Doctrine\ORM\EntityManagerInterface;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\TripDescriptor;
use Google\Transit\Realtime\TripUpdate;
use Google\Transit\Realtime\TripUpdate\StopTimeEvent;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Appairage d'un écran vu par l'écran : il affiche un code sur /display,
 * interroge /display/pairing, et reçoit son cookie une fois le code saisi.
 */
final class DisplayPairingTest extends WebTestCase
{
    use Factories;
    use RealtimeFeedTrait;
    use ResetDatabase;
    use ScreenPairingTrait;

    private const string CODE_PATTERN = '/^[2-9A-HJKMNP-Z]{3}-[2-9A-HJKMNP-Z]{3}$/';

    private KernelBrowser $client;

    private MockClock $clock;

    private User $owner;

    private Device $device;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        // Le cookie de l'écran est Secure : BrowserKit ne l'envoie qu'en HTTPS.
        $this->client = self::createClient(server: ['HTTPS' => 'on']);
        // Demandes d'appairage et compteurs en mémoire : le conteneur doit
        // survivre d'une requête à l'autre.
        $this->client->disableReboot();

        $this->clock = new MockClock(self::NOW, date_default_timezone_get());
        self::getContainer()->set('clock', $this->clock);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->owner = UserFactory::createOne();
        $this->device = DeviceFactory::createOne(['owner' => $this->owner, 'label' => 'Salon']);

        $quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager->persist(new Route('R1', '1', 'FF5500', 'FFFFFF'));
        $entityManager->persist($quay);

        $configuration = new DisplayConfiguration($this->device, $quay)->setLabel('Centre');
        $this->device->addDisplayConfiguration($configuration);
        $entityManager->persist($configuration);
        $entityManager->flush();
    }

    public function testUnpairedScreenShowsAPairingCode(): void
    {
        $code = $this->displayedCode();

        self::assertMatchesRegularExpression(self::CODE_PATTERN, $code);
        self::assertSelectorExists('[data-controller="pairing"]');
        self::assertSelectorTextSame('.pairing__instruction', 'Scannez le QR code ou saisissez le code sur localhost → Dispositifs');
    }

    public function testPairingScreenShowsAQrCodeToThePairingPageOfItsCode(): void
    {
        $code = $this->displayedCode();
        $qrCode = $this->client->getCrawler()->filter('.pairing__qr[role="img"]');

        self::assertCount(1, $qrCode->filter('svg'));
        self::assertCount(0, $qrCode->filter('[style], script'));
        self::assertSame('QR code vers https://localhost'.$this->scannedPairingPath($code), $qrCode->attr('aria-label'));
    }

    public function testEnteredCodePairsTheScreenWhichThenShowsItsPassages(): void
    {
        $this->serveRealtimeFeed();
        $code = $this->displayedCode();

        $this->ownerEntersCode($code);
        self::assertResponseRedirects(\sprintf('/devices/%d', $this->device->getId()));

        $this->pairScreen();

        $crawler = $this->client->request('GET', '/display');

        self::assertResponseIsSuccessful();
        self::assertSame('display', $crawler->filter('body')->attr('data-controller'));
        self::assertSame(['CENTRE VILLE'], $crawler->filter('.screen__stop')->each(static fn ($stop): string => $stop->text()));
        self::assertSame(['1'], $crawler->filter('.passage__route')->each(static fn ($route): string => $route->text()));
        self::assertSame('/display?fragment=1', $crawler->filter('body')->attr('data-display-url-value'));
        // Les passages sont des instants ; seule l'horloge de l'écran dépend
        // du fuseau, celui du réseau quel que soit celui du navigateur.
        self::assertSame('Europe/Paris', $crawler->filter('body')->attr('data-display-time-zone-value'));
    }

    public function testReloadingWithinTheMinuteKeepsTheCodeThenANewOneComes(): void
    {
        $code = $this->displayedCode();
        $this->clock->sleep(59);

        self::assertSame($code, $this->displayedCode());

        $this->clock->sleep(1);

        self::assertNotSame($code, $this->displayedCode());
    }

    public function testPollingShowsTheCodeAndItsExpiry(): void
    {
        $code = $this->displayedCode();

        $crawler = $this->client->request('GET', '/display/pairing');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSame($code, $crawler->filter('.pairing__code')->text());
        self::assertSame('60', $crawler->filter('.pairing')->attr('data-remaining-seconds'));

        $this->clock->sleep(20);
        $crawler = $this->client->request('GET', '/display/pairing');

        self::assertSame('40', $crawler->filter('.pairing')->attr('data-remaining-seconds'));
        self::assertSame('60', $crawler->filter('.pairing')->attr('data-duration-seconds'));
    }

    public function testPreviousCodeIsAcceptedDuringOneMoreMinute(): void
    {
        $previous = $this->displayedCode();
        $this->clock->sleep(90);
        self::assertNotSame($previous, $this->displayedCode());

        $this->ownerEntersCode($previous);

        self::assertResponseRedirects(\sprintf('/devices/%d', $this->device->getId()));
        $this->pairScreen();
    }

    public function testCodeIsRefusedAfterTwoMinutes(): void
    {
        $code = $this->displayedCode();
        $this->clock->sleep(121);

        $this->ownerEntersCode($code);

        $this->assertCodeRefused();
    }

    public function testCodeServesOnlyOnce(): void
    {
        $code = $this->displayedCode();
        $this->ownerEntersCode($code);

        $this->ownerEntersCode($code, DeviceFactory::createOne(['owner' => $this->owner]));

        $this->assertCodeRefused();
    }

    public function testCodeCanBeTypedWithoutDashInLowerCase(): void
    {
        $code = $this->displayedCode();

        $this->ownerEntersCode(strtolower(str_replace('-', '', $code)));

        self::assertResponseRedirects(\sprintf('/devices/%d', $this->device->getId()));
    }

    public function testOnlyTheScreenThatShowedTheCodeReceivesTheCookie(): void
    {
        $code = $this->displayedCode();
        $requestCookie = $this->client->getCookieJar()->get('STOPCAST_DISPLAY_PAIRING', '/', 'localhost');
        self::assertNotNull($requestCookie);
        $this->ownerEntersCode($code);

        // Un autre navigateur, qui connaît le code mais pas le secret de la demande.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/display/pairing');

        self::assertResponseIsSuccessful();
        self::assertNull($this->screenCookie());

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($requestCookie);
        $this->pairScreen();
    }

    public function testOnlyTheHashOfTheScreenSecretIsStored(): void
    {
        $this->pairedScreen();

        $cookie = $this->screenCookie();
        self::assertNotNull($cookie);
        self::assertSame(64, \strlen($cookie->getValue()));
        self::assertSame(hash('sha256', $cookie->getValue()), $this->storedDevice($this->device)->getScreenSecretHash());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', strtolower((string) $cookie->getSameSite()));
    }

    public function testScreenCookiesAreSecureOnlyOverHttps(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/display');

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('STOPCAST_DISPLAY_PAIRING', $cookies[0]->getName());
        self::assertFalse($cookies[0]->isSecure());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testCodeGenerationIsLimitedPerAddress(): void
    {
        for ($i = 0; $i < 150; ++$i) {
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', '/display/pairing');
            self::assertResponseIsSuccessful();
        }

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/display');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextSame('.pairing__message', 'Trop de demandes, nouvel essai dans quelques minutes');
        self::assertSelectorNotExists('.pairing__code');
    }

    public function testScreenCookieIsRenewedOnEachResponse(): void
    {
        $this->serveRealtimeFeed();
        $this->pairedScreen();
        $this->clock->sleep(86400);

        $this->client->request('GET', '/display?fragment=1');

        self::assertResponseIsSuccessful();
        $cookie = $this->screenCookie();
        self::assertNotNull($cookie);
        self::assertSame(new \DateTimeImmutable(self::NOW)->modify('+401 days')->getTimestamp(), (int) $cookie->getExpiresTime());
    }

    public function testFragmentHoldsTheScreensAndCadences(): void
    {
        $this->serveRealtimeFeed();
        $this->pairedScreen();

        $crawler = $this->client->request('GET', '/display?fragment=1');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSame('60', $crawler->filter('[data-display-target="content"]')->attr('data-refresh-interval'));
        self::assertSame(['1'], $crawler->filter('.passage__route')->each(static fn ($route): string => $route->text()));
    }

    public function testPairingAnotherScreenSendsTheFirstBackToPairing(): void
    {
        $this->pairedScreen();
        $first = $this->screenCookie();
        self::assertNotNull($first);

        $this->client->getCookieJar()->clear();
        $this->pairedScreen();

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($first);
        $this->assertSentBackToPairing();
    }

    public function testUnpairingSendsTheScreenBackToPairing(): void
    {
        $this->pairedScreen();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $this->device->getId()));
        $this->client->submit($crawler->selectButton('Dissocier')->form());
        self::assertResponseRedirects(\sprintf('/devices/%d', $this->device->getId()));
        self::assertFalse($this->storedDevice($this->device)->hasPairedScreen());

        $this->assertSentBackToPairing();
    }

    public function testScreenUnpairedByItsOwnerCanBePairedByAnotherUser(): void
    {
        $this->pairedScreen();
        $device = $this->storedDevice($this->device);
        $device->unpairScreen();

        $this->entityManager->flush();
        $this->client->request('GET', '/display');
        self::assertResponseRedirects('/display');

        $otherUser = UserFactory::createOne();
        $otherDevice = DeviceFactory::createOne(['owner' => $otherUser]);
        $code = $this->displayedCode();
        $this->client->loginUser($otherUser);
        $this->enterCode($code, $otherDevice);
        self::assertResponseRedirects(\sprintf('/devices/%d', $otherDevice->getId()));

        $this->pairScreen();
    }

    public function testDisabledDeviceShowsADisabledScreenAndKeepsItsCookie(): void
    {
        $this->pairedScreen();
        $device = $this->storedDevice($this->device);
        $device->setActive(false);

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/display');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.screen__message', 'Écran désactivé');
        self::assertCount(0, $crawler->filter('.passage'));
        self::assertNotNull($this->screenCookie());

        $this->client->request('GET', '/display?fragment=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.screen__message', 'Écran désactivé');
    }

    public function testUnreadableFeedFailsTheRefreshAndKeepsThePageAlive(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])));
        $this->pairedScreen();

        $this->client->request('GET', '/display?fragment=1');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/display');

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('body')->attr('data-display-offline'));
        self::assertSame('/display?fragment=1', $crawler->filter('body')->attr('data-display-url-value'));
        self::assertCount(0, $crawler->filter('.passage'));
    }

    public function testFragmentWithoutScreenCookieIsUnauthorized(): void
    {
        $this->client->request('GET', '/display?fragment=1');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserSessionDoesNotOpenTheDisplay(): void
    {
        $this->serveRealtimeFeed();
        // Vraie connexion par le formulaire : loginUser() injecterait le jeton quel que soit le firewall.
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $this->owner->getEmail(),
            '_password' => UserFactory::DEFAULT_PASSWORD,
        ]));
        self::assertResponseRedirects('/devices');

        $this->client->request('GET', '/display');

        self::assertSelectorExists('.pairing__code');
        self::assertSelectorNotExists('.passage');
    }

    public function testScreenCookieDoesNotOpenTheUserSpace(): void
    {
        $this->pairedScreen();

        $this->client->request('GET', '/devices');
        self::assertResponseRedirects('/');

        // /display-configurations reste sur le firewall des comptes.
        $this->client->request('GET', '/display-configurations/new');
        self::assertResponseRedirects('/');
    }

    /**
     * Le propriétaire se connecte et saisit le code sur la fiche de son dispositif.
     */
    private function ownerEntersCode(string $code, ?Device $device = null): void
    {
        $this->client->loginUser($this->owner);
        $this->enterCode($code, $device ?? $this->device);
    }

    /**
     * L'écran interroge le serveur et reçoit le cookie de son dispositif.
     */
    private function pairScreen(): void
    {
        $this->receiveScreenCookie();
        self::assertNotNull($this->screenCookie());
    }

    /**
     * Un écran appairé au dispositif de test, puis l'espace web quitté : le
     * client ne porte plus que les cookies de l'écran.
     */
    private function pairedScreen(): void
    {
        $code = $this->displayedCode();
        $this->ownerEntersCode($code);
        $jar = $this->client->getCookieJar();
        $screenCookies = array_filter($jar->all(), static fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'STOPCAST_DISPLAY'));
        $jar->clear();
        array_map($jar->set(...), $screenCookies);
        $this->pairScreen();
    }

    /**
     * Cookie refusé : le fragment répond 401, la page se recharge sur elle-même
     * puis montre l'écran d'appairage ; le cookie est effacé.
     */
    private function assertSentBackToPairing(): void
    {
        $cookie = $this->screenCookie();
        self::assertNotNull($cookie);

        $this->client->request('GET', '/display?fragment=1');
        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->screenCookie());

        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/display');
        self::assertResponseRedirects('/display');
        self::assertNull($this->screenCookie());

        $this->client->followRedirect();
        self::assertSelectorExists('.pairing__code');
    }

    private function assertCodeRefused(): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#pair_screen', 'Ce code est inconnu ou a expiré.');
    }

    private function screenCookie(): ?Cookie
    {
        return $this->client->getCookieJar()->get('STOPCAST_DISPLAY', '/', 'localhost');
    }

    /**
     * Le flux temps réel annonce un passage de la ligne 1 dans 5 minutes au quai S1.
     */
    private function serveRealtimeFeed(): void
    {
        // Daté de l'horloge du test à chaque requête : un flux à l'heure fixée
        // serait périmé une fois l'horloge avancée.
        self::getContainer()->set('http_client', new MockHttpClient(fn (): MockResponse => self::feedResponse([
            new FeedEntity()->setTripUpdate(new TripUpdate()
                ->setTrip(new TripDescriptor()->setTripId('T1')->setRouteId('R1'))
                ->setStopTimeUpdate([new StopTimeUpdate()->setStopId('S1')->setDeparture(new StopTimeEvent()->setTime(self::at(5)))])),
        ], $this->clock->now()->getTimestamp())));
    }
}
