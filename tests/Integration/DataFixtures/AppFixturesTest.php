<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AppFixturesTest extends KernelTestCase
{
    use DevelopmentQuaySelectorFactoryTrait;
    use Factories;
    use ResetDatabase;

    private const string TOKEN = 'jeton-de-developpement';

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $entityManager = $this->entityManager();

        $route = new Route('R1', '1', 'FF5500', 'FFFFFF');
        $entityManager->persist($route);

        foreach (['S1', 'S2', 'S3', 'S4'] as $stopId) {
            $entityManager->persist(new Quay($stopId, 'QUAI '.$stopId, 38.5, -28.6, 'P'.$stopId));
        }

        $entityManager->persist(new Trip('T1', $route, 'QUAI S4', 'S4'));
        $entityManager->flush();
    }

    public function testFixedDeviceShowsTheBusiestQuays(): void
    {
        $this->load(self::TOKEN);

        $device = $this->fixedDevice();
        $configurations = $device->getDisplayConfigurations()->toArray();
        usort($configurations, static fn (DisplayConfiguration $a, DisplayConfiguration $b): int => $a->getPosition() <=> $b->getPosition());

        self::assertSame(AppFixtures::FIXED_DEVICE_LABEL, $device->getLabel());
        self::assertTrue($device->isActive());
        self::assertSame([0, 1, 2], array_map(static fn (DisplayConfiguration $configuration): int => $configuration->getPosition(), $configurations));
        self::assertSame(['S2', 'S3', 'S1'], array_map(static fn (DisplayConfiguration $configuration): string => $configuration->getQuay()->getGtfsStopId(), $configurations));
        self::assertCount(3, array_filter(array_map(static fn (DisplayConfiguration $configuration): bool => $configuration->isActive(), $configurations)));
    }

    public function testRandomDevicesAreCreatedAlongsideTheFixedOne(): void
    {
        $this->load(self::TOKEN);

        self::assertSame(6, $this->entityManager()->getRepository(Device::class)->count());
    }

    public function testReloadingReplacesDevicesAndKeepsTheImportedData(): void
    {
        $this->load(self::TOKEN);
        $this->load(self::TOKEN);

        $entityManager = $this->entityManager();
        self::assertSame(6, $entityManager->getRepository(Device::class)->count());
        self::assertSame(3, $entityManager->getRepository(DisplayConfiguration::class)->count(['device' => $this->fixedDevice()]));
        self::assertSame(4, $entityManager->getRepository(Quay::class)->count());
        self::assertSame(1, $entityManager->getRepository(Route::class)->count());
        self::assertSame(1, $entityManager->getRepository(Trip::class)->count());
    }

    public function testEmptyTokenCreatesOnlyRandomDevicesAndSaysSo(): void
    {
        $this->load('');

        self::assertSame(5, $this->entityManager()->getRepository(Device::class)->count());
        self::assertNull($this->entityManager()->getRepository(Device::class)->findOneBy(['label' => AppFixtures::FIXED_DEVICE_LABEL]));
        self::assertStringContainsString('FIXTURES_DEVICE_TOKEN', implode("\n", $this->logs));
    }

    public function testTooLongTokenFailsBeforeRemovingAnything(): void
    {
        $this->load(self::TOKEN);

        try {
            $this->load(str_repeat('a', 65));
            self::fail('Un jeton de 65 caractères doit être refusé.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('64', $invalidArgumentException->getMessage());
        }

        self::assertSame(6, $this->entityManager()->getRepository(Device::class)->count());
    }

    public function testUnavailableFeedStillLoadsAndSaysTheFallbackWasUsed(): void
    {
        $this->load(self::TOKEN, available: false);

        self::assertCount(3, $this->fixedDevice()->getDisplayConfigurations());
        self::assertStringContainsString('indisponible', implode("\n", $this->logs));
    }

    private function load(string $token, bool $available = true): void
    {
        $selector = $available
            ? $this->selectorWithFeed(['T1' => ['S1', 'S2', 'S3', 'S4'], 'T2' => ['S2', 'S3'], 'T3' => ['S2']])
            : $this->selectorWithUnavailableFeed();

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        new AppFixtures($selector, $logger, $token)->load($this->entityManager());
        $this->entityManager()->clear();
        $this->logs = [...$this->logs, ...$logger->messages];
    }

    private function fixedDevice(): Device
    {
        $device = $this->entityManager()->getRepository(Device::class)->findOneBy(['token' => self::TOKEN]);
        self::assertInstanceOf(Device::class, $device);

        return $device;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
