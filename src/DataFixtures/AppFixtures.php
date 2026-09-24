<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Repository\QuayRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    private const string FIXED_DEVICE_LABEL = 'Raspberry Pi Test';
    private const string FIXED_QUAY_GTFS_STOP_ID = '0:MAI02';

    public function __construct(
        private readonly QuayRepository $quayRepository,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->loadRandomDevices();
        $this->loadFixedDeviceForManualTesting();
    }

    private function loadRandomDevices(): void
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

    /**
     * Device + DisplayConfiguration fixes, pour tester manuellement (Postman, curl...)
     * sans avoir à repêcher un token/quai aléatoire à chaque reload des fixtures.
     *
     * Si le quai demandé (0:MAI02) n'existe pas — GTFS pas encore importé, ou
     * arrêt disparu d'un import ultérieur — on retombe sur le tirage aléatoire
     * déjà géré par DisplayConfigurationFactory::defaults() plutôt que d'échouer.
     */
    private function loadFixedDeviceForManualTesting(): void
    {
        $fixedDevice = DeviceFactory::createOne([
            'label' => self::FIXED_DEVICE_LABEL,
        ]);

        $fixedQuay = $this->quayRepository->findOneByGtfsStopId(self::FIXED_QUAY_GTFS_STOP_ID);

        $overrides = [
            'device' => $fixedDevice,
            'position' => 0,
            'label' => 'Config test manuel',
        ];

        if (null !== $fixedQuay) {
            $overrides['quay'] = $fixedQuay;
        } else {
            fwrite(\STDERR, \sprintf(
                "  [AppFixtures] Quai \"%s\" introuvable, fallback sur un quai aléatoire.\n",
                self::FIXED_QUAY_GTFS_STOP_ID,
            ));
        }

        DisplayConfigurationFactory::createOne($overrides);
    }
}
