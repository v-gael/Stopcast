<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        DeviceFactory::createMany(5);

        foreach (DeviceFactory::all() as $device) {
            $configCount = random_int(2, 4);

            for ($position = 0; $position < $configCount; ++$position) {
                DisplayConfigurationFactory::createOne([
                    'device' => $device,
                    'position' => $position,
                ]);
            }
        }
    }
}
