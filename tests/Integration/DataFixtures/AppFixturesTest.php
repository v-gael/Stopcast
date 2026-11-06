<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\Route;
use App\Entity\Trip;
use App\Entity\User;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AppFixturesTest extends KernelTestCase
{
    use DevelopmentQuaySelectorFactoryTrait;
    use Factories;
    use ResetDatabase;

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
        $this->load();

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
        $this->load();

        self::assertSame(6, $this->entityManager()->getRepository(Device::class)->count());
    }

    public function testReloadingReplacesDevicesAndKeepsTheImportedData(): void
    {
        $this->load();
        $this->load();

        $entityManager = $this->entityManager();
        self::assertSame(6, $entityManager->getRepository(Device::class)->count());
        self::assertSame(3, $entityManager->getRepository(DisplayConfiguration::class)->count(['device' => $this->fixedDevice()]));
        self::assertSame(4, $entityManager->getRepository(Quay::class)->count());
        self::assertSame(1, $entityManager->getRepository(Route::class)->count());
        self::assertSame(1, $entityManager->getRepository(Trip::class)->count());
    }

    public function testDevAdministratorCanLogInWithTheDocumentedCredentials(): void
    {
        $this->load();
        $this->load();

        $users = $this->entityManager()->getRepository(User::class)->findBy(['email' => AppFixtures::DEV_USER_EMAIL]);
        self::assertCount(1, $users);
        self::assertTrue($users[0]->isAdmin());

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        self::assertTrue($passwordHasher->isPasswordValid($users[0], AppFixtures::DEV_USER_PASSWORD));
    }

    public function testDevAdministratorOwnsEveryDevice(): void
    {
        $this->load();

        $owners = array_map(
            static fn (Device $device): string => $device->getOwner()->getEmail(),
            $this->entityManager()->getRepository(Device::class)->findAll(),
        );

        self::assertSame([AppFixtures::DEV_USER_EMAIL], array_values(array_unique($owners)));
    }

    public function testReloadingKeepsTheOtherAccounts(): void
    {
        UserFactory::createOne(['email' => 'lea@example.com']);

        $this->load();

        self::assertSame(2, $this->entityManager()->getRepository(User::class)->count());
    }

    public function testUnavailableFeedStillLoadsAndSaysTheFallbackWasUsed(): void
    {
        $this->load(available: false);

        self::assertCount(3, $this->fixedDevice()->getDisplayConfigurations());
        self::assertStringContainsString('indisponible', implode("\n", $this->logs));
    }

    private function load(bool $available = true): void
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

        new AppFixtures($selector, $logger)->load($this->entityManager());
        $this->entityManager()->clear();
        $this->logs = [...$this->logs, ...$logger->messages];
    }

    private function fixedDevice(): Device
    {
        $device = $this->entityManager()->getRepository(Device::class)->findOneBy(['label' => AppFixtures::FIXED_DEVICE_LABEL]);
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
