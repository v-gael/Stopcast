<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * La suppression d'un compte se fait en base : ce qui en dépend doit suivre
 * sans que l'ORM ait chargé les dispositifs.
 */
final class DeviceOwnershipTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDeletingAUserRemovesTheirDevicesAndConfigurationsOnly(): void
    {
        $lea = UserFactory::createOne();
        $noe = UserFactory::createOne();
        $leaDevice = DeviceFactory::createOne(['owner' => $lea]);
        $noeDevice = DeviceFactory::createOne(['owner' => $noe]);
        $quay = new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1');
        $entityManager = $this->entityManager();
        $entityManager->persist($quay);
        $entityManager->flush();
        DisplayConfigurationFactory::createOne(['device' => $leaDevice, 'quay' => $quay]);
        DisplayConfigurationFactory::createOne(['device' => $noeDevice, 'quay' => $quay]);

        $entityManager->createQuery(\sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))
            ->setParameter('id', $lea->getId())
            ->execute();
        $entityManager->clear();

        self::assertSame([$noeDevice->getId()], array_map(static fn (Device $device): ?int => $device->getId(), $entityManager->getRepository(Device::class)->findAll()));
        self::assertSame(1, $entityManager->getRepository(DisplayConfiguration::class)->count());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
