<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\AccountDataExporter;
use App\Entity\Quay;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\QueryCountTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AccountDataExporterTest extends KernelTestCase
{
    use Factories;
    use QueryCountTrait;
    use ResetDatabase;

    public function testDevicesConfigurationsAndQuaysAreLoadedInASingleQuery(): void
    {
        $user = UserFactory::createOne(['email' => 'admin@example.com', 'role' => Role::Admin]);

        foreach (['Salon', 'Cuisine'] as $index => $label) {
            $device = DeviceFactory::createOne(['owner' => $user, 'label' => $label]);

            foreach ([1, 2] as $position) {
                DisplayConfigurationFactory::createOne([
                    'device' => $device,
                    'quay' => $this->quay(\sprintf('S%d%d', $index, $position)),
                    'position' => $position,
                ]);
            }
        }

        $export = null;
        $queries = $this->countQueries(function () use ($user, &$export): void {
            $export = $this->exporter()->export($user, new \DateTimeImmutable('2026-10-30 14:30:00'));
        });

        self::assertSame(1, $queries);
        self::assertIsArray($export);
        self::assertSame('ROLE_ADMIN', $export['account']['role']);
        self::assertSame(['Salon', 'Cuisine'], array_column($export['devices'], 'label'));
        $configurations = $export['devices'][1]['displayConfigurations'];
        self::assertIsArray($configurations);
        self::assertSame(['S11', 'S12'], array_column(array_column($configurations, 'quay'), 'gtfsStopId'));
    }

    private function quay(string $stopId): Quay
    {
        $quay = new Quay($stopId, 'QUAI '.$stopId, 38.5, -28.6, 'P'.$stopId);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($quay);
        $entityManager->flush();

        return $quay;
    }

    private function exporter(): AccountDataExporter
    {
        $exporter = self::getContainer()->get(AccountDataExporter::class);
        self::assertInstanceOf(AccountDataExporter::class, $exporter);

        return $exporter;
    }
}
