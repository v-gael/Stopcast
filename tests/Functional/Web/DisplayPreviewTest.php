<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Display\ScreenPaginator;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\AppLogsTrait;
use App\Tests\Support\WebTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DisplayPreviewTest extends WebTestCase
{
    use AppLogsTrait;
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private KernelBrowser $client;

    private User $user;

    private Device $device;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = UserFactory::createOne();
        $this->device = DeviceFactory::createOne(['owner' => $this->user, 'label' => 'Salon']);
        $this->client->loginUser($this->user);
    }

    public function testDevicePreviewRotatesItsActiveConfigurationsOnSamplePassages(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE', position: 1);
        $this->configure('Masquée', 'MAIRIE', position: 0, active: false);
        $this->configure('Centre', 'CENTRE VILLE', position: 2);

        $crawler = $this->client->request('GET', $this->devicePreview());

        self::assertResponseIsSuccessful();
        // Page autonome : ni navigation ni pied de page de l'espace web.
        self::assertSelectorNotExists('header, nav, footer');
        self::assertSame(['GARE ROUTIERE', 'CENTRE VILLE'], $crawler->filter('.screen .screen__stop')->each(static fn (Crawler $stop): string => $stop->text()));
        // Un seul écran visible à la fois, le premier de la rotation.
        self::assertSame([null, ''], $crawler->filter('.screen')->each(static fn (Crawler $screen): ?string => $screen->attr('hidden')));
        self::assertCount(ScreenPaginator::PASSAGES_PER_SCREEN, $crawler->filter('.screen')->first()->filter('.passage'));
        self::assertSame('display', $crawler->filter('body')->attr('data-controller'));
        self::assertSame($this->devicePreview().'?fragment=1', $crawler->filter('body')->attr('data-display-url-value'));
    }

    public function testSamplePassagesFillAScreenWithTheirColorsAndDelays(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');

        $crawler = $this->client->request('GET', $this->devicePreview());

        $passages = $crawler->filter('.passage');
        self::assertCount(ScreenPaginator::PASSAGES_PER_SCREEN, $passages);
        $first = $passages->first();
        self::assertSame('1', $first->filter('.passage__route')->text());
        self::assertSame('#E2001A', $first->filter('.passage__route')->attr('data-route-color'));
        self::assertSame('#FFFFFF', $first->filter('.passage__route')->attr('data-route-text-color'));
        self::assertSame('2 min', $first->filter('.passage__minutes')->text());
        self::assertSame('GARE CENTRALE', $first->filter('.passage__headsign')->text());
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $crawler->filter('.screen__clock')->first()->text());
    }

    public function testDeviceWithoutActiveConfigurationShowsItsStateScreen(): void
    {
        $this->configure('Masquée', 'MAIRIE', active: false);

        $crawler = $this->client->request('GET', $this->devicePreview());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.screen'));
        self::assertSelectorTextSame('.screen__message', 'Aucun quai affiché');
    }

    public function testDevicePreviewLeavesOutDeprecatedQuays(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');
        $this->configure('Disparu', 'VIEUX QUAI', position: 1, deprecated: true);

        $crawler = $this->client->request('GET', $this->devicePreview());

        self::assertSame(['GARE ROUTIERE'], $crawler->filter('.screen .screen__stop')->each(static fn (Crawler $stop): string => $stop->text()));
    }

    public function testDevicePreviewWithOnlyDeprecatedQuaysShowsItsStateScreen(): void
    {
        $this->configure('Disparu', 'VIEUX QUAI', deprecated: true);

        $this->client->request('GET', $this->devicePreview());

        self::assertSelectorTextSame('.screen__message', 'Aucun quai affiché');
    }

    public function testConfigurationPreviewStillShowsADeprecatedQuay(): void
    {
        $configuration = $this->configure('Disparu', 'VIEUX QUAI', deprecated: true);

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/preview', $configuration->getId()));

        self::assertSame(['VIEUX QUAI'], $crawler->filter('.screen .screen__stop')->each(static fn (Crawler $stop): string => $stop->text()));
        self::assertCount(ScreenPaginator::PASSAGES_PER_SCREEN, $crawler->filter('.passage'));
    }

    public function testConfigurationPreviewShowsThatConfigurationEvenHidden(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');
        $hidden = $this->configure('Masquée', 'MAIRIE', position: 1, active: false);

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/preview', $hidden->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(['MAIRIE'], $crawler->filter('.screen .screen__stop')->each(static fn (Crawler $stop): string => $stop->text()));
        self::assertCount(ScreenPaginator::PASSAGES_PER_SCREEN, $crawler->filter('.passage'));
    }

    public function testFragmentHoldsOnlyTheScreensAndTheCurrentCadences(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');
        $this->changeCadences(screenDuration: 12, refreshInterval: 90);

        $crawler = $this->client->request('GET', $this->devicePreview().'?fragment=1');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        $content = $crawler->filter('[data-display-target="content"]');
        self::assertSame('12', $content->attr('data-screen-duration'));
        self::assertSame('90', $content->attr('data-refresh-interval'));
        self::assertCount(1, $content->filter('.screen'));
    }

    public function testPreviewCanBeFramedByTheSiteOnly(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');

        $this->client->request('GET', $this->devicePreview());

        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        // Les autres pages restent interdites dans un cadre.
        $this->client->request('GET', \sprintf('/devices/%d', $this->device->getId()));

        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testDevicePageFramesTheWholeRotationInOneLivePreview(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');
        $this->configure('Masquée', 'MAIRIE', position: 1, active: false);
        $this->configure(null, 'CENTRE VILLE', position: 2);

        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $this->device->getId()));

        $frames = $crawler->filter('iframe');
        self::assertSame([$this->liveDevicePreview()], $frames->each(static fn (Crawler $frame): ?string => $frame->attr('src')));
        self::assertSame(['480', '320'], [$frames->attr('width'), $frames->attr('height')]);
        self::assertSame(\sprintf('Aperçu de « %s »', $this->device->getLabel()), $frames->attr('title'));
    }

    public function testDevicePageWithoutActiveConfigurationFramesTheLivePreview(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/devices/%d', $this->device->getId()));

        self::assertSame([$this->liveDevicePreview()], $crawler->filter('iframe')->each(static fn (Crawler $frame): ?string => $frame->attr('src')));
    }

    public function testConfigurationFormFramesItsPreviewMarkedAsAnExample(): void
    {
        $configuration = $this->configure('Gare', 'GARE ROUTIERE');

        $crawler = $this->client->request('GET', \sprintf('/display-configurations/%d/edit', $configuration->getId()));

        self::assertSame([\sprintf('/display-configurations/%d/preview', $configuration->getId())], $crawler->filter('iframe')->each(static fn (Crawler $frame): ?string => $frame->attr('src')));
        self::assertSelectorTextContains('[data-sample-notice]', 'Exemple');
    }

    public function testDevicePageShowsNoExampleNotice(): void
    {
        $this->client->request('GET', \sprintf('/devices/%d', $this->device->getId()));

        self::assertSelectorNotExists('[data-sample-notice]');
    }

    public function testLivePreviewRotatesTheRealPassagesOfTheActiveConfigurations(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE', route: 'L4');
        $this->configure('Masquée', 'MAIRIE', position: 1, active: false, route: 'L7');

        $crawler = $this->client->request('GET', $this->liveDevicePreview());

        self::assertResponseIsSuccessful();
        self::assertSame(['GARE ROUTIERE'], $crawler->filter('.screen .screen__stop')->each(static fn (Crawler $stop): string => $stop->text()));
        // Les deux passages du flux simulé (RealtimeFeedMock), pas ceux d'exemple.
        self::assertSame(['L4', 'L4'], $crawler->filter('.passage__route')->each(static fn (Crawler $route): string => $route->text()));
        self::assertSame(['3 min', '12 min'], $crawler->filter('.passage__minutes')->each(static fn (Crawler $minutes): string => $minutes->text()));
        self::assertSame($this->liveDevicePreview().'?fragment=1', $crawler->filter('body')->attr('data-display-url-value'));
        self::assertNull($crawler->filter('body')->attr('data-display-offline'));
    }

    public function testLivePreviewShowsAQuayWithoutPassageLikeTheScreen(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE');

        $this->client->request('GET', $this->liveDevicePreview());

        $crawler = $this->client->getCrawler();
        self::assertCount(0, $crawler->filter('.passage'));
        self::assertSame(['Pas de passage prévu actuellement'], $crawler->filter('.screen__message:not([hidden])')->each(static fn (Crawler $message): string => $message->text()));
    }

    public function testLivePreviewOfADisabledDeviceShowsTheDisabledScreen(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE', route: 'L4');
        $this->disableDevice();

        $crawler = $this->client->request('GET', $this->liveDevicePreview());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.passage'));
        self::assertSelectorTextSame('.screen__message', 'Écran désactivé');
    }

    public function testLivePreviewFailingForTheFeedBehavesLikeTheScreen(): void
    {
        $this->configure('Gare', 'GARE ROUTIERE', route: 'L4');
        self::getContainer()->set('http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])));
        $this->client->disableReboot();
        $logs = $this->appLogs();

        // Le rafraîchissement échoue : l'aperçu garde ce qu'il affichait.
        $this->client->request('GET', $this->liveDevicePreview().'?fragment=1');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertTrue($logs->hasWarningThatContains('GTFS-RT feed unavailable.'));

        // Premier chargement : l'écran « indisponible », qui réessaie.
        $crawler = $this->client->request('GET', $this->liveDevicePreview());

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('body')->attr('data-display-offline'));
        self::assertCount(0, $crawler->filter('.passage'));
        self::assertSelectorTextSame('.screen__message', 'Horaires temps réel indisponibles');
    }

    public function testLivePreviewCanBeFramedByTheSiteOnly(): void
    {
        $this->client->request('GET', $this->liveDevicePreview());

        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function previews(): iterable
    {
        yield 'dispositif' => ['device'];
        yield 'dispositif, fragment' => ['device-fragment'];
        yield 'dispositif, passages réels' => ['live'];
        yield 'dispositif, passages réels, fragment' => ['live-fragment'];
        yield 'configuration' => ['configuration'];
    }

    #[DataProvider('previews')]
    public function testAnotherUsersPreviewIsForbiddenEvenToAnAdministrator(string $preview): void
    {
        $url = $this->previewUrl($preview);
        $this->client->loginUser(UserFactory::createOne(['role' => Role::Admin]));

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('previews')]
    public function testPreviewRequiresLoggingIn(string $preview): void
    {
        $url = $this->previewUrl($preview);
        $this->client->getCookieJar()->clear();
        $this->client->restart();

        $this->client->request('GET', $url);

        self::assertResponseRedirects('/');
    }

    /**
     * Un aperçu resté ouvert continue de relire son fragment après la perte de
     * la session : la connexion suivante ne doit pas y ramener.
     */
    public function testLoggingInAfterAnOpenPreviewLostItsSessionOpensTheDevices(): void
    {
        $url = $this->devicePreview().'?fragment=1';
        $this->client->getCookieJar()->clear();
        $this->client->restart();

        $this->client->xmlHttpRequest('GET', $url);
        self::assertResponseRedirects('/');

        $this->logIn($this->user->getEmail(), UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/devices');
    }

    private function previewUrl(string $preview): string
    {
        return match ($preview) {
            'device' => $this->devicePreview(),
            'device-fragment' => $this->devicePreview().'?fragment=1',
            'live' => $this->liveDevicePreview(),
            'live-fragment' => $this->liveDevicePreview().'?fragment=1',
            default => \sprintf('/display-configurations/%d/preview', $this->configure('Gare', 'GARE ROUTIERE')->getId()),
        };
    }

    private function devicePreview(): string
    {
        return \sprintf('/devices/%d/preview', $this->device->getId());
    }

    private function liveDevicePreview(): string
    {
        return \sprintf('/devices/%d/preview/live', $this->device->getId());
    }

    /**
     * @param string|null $route ligne desservant le quai : le flux simulé (RealtimeFeedMock) y annonce deux passages
     */
    private function configure(?string $label, string $quayName, int $position = 0, bool $active = true, bool $deprecated = false, ?string $route = null): DisplayConfiguration
    {
        $entityManager = $this->entityManager();
        $device = $entityManager->find(Device::class, $this->device->getId());
        self::assertInstanceOf(Device::class, $device);

        $quay = new Quay('S'.$quayName, $quayName, 38.5, -28.6, 'P'.$quayName);
        if ($deprecated) {
            $quay->markDeprecated();
        }

        if (null !== $route) {
            $servingRoute = new Route('R'.$route, $route, '0076BF', 'FFFFFF');
            $quay->addRoute($servingRoute);
            $entityManager->persist($servingRoute);
        }

        $configuration = new DisplayConfiguration($device, $quay)->setLabel($label)->setPosition($position)->setActive($active);
        $entityManager->persist($quay);
        $entityManager->persist($configuration);
        $entityManager->flush();
        $entityManager->clear();

        return $configuration;
    }

    private function disableDevice(): void
    {
        $entityManager = $this->entityManager();
        $device = $entityManager->find(Device::class, $this->device->getId());
        self::assertInstanceOf(Device::class, $device);
        $device->setActive(false);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function changeCadences(int $screenDuration, int $refreshInterval): void
    {
        $entityManager = $this->entityManager();
        $device = $entityManager->find(Device::class, $this->device->getId());
        self::assertInstanceOf(Device::class, $device);
        $device->setScreenDuration($screenDuration)->setRefreshInterval($refreshInterval);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
