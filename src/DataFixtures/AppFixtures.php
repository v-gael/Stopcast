<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\DisplayConfigurationFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chargées avec --append (make fixtures) pour garder le référentiel importé :
 * seuls les appareils, leurs configurations et le compte de développement,
 * qui les possède tous, sont remplacés.
 */
final class AppFixtures extends Fixture
{
    public const string FIXED_DEVICE_LABEL = 'Écran de développement';

    /** Identifiants documentés dans docs/developpement.md. */
    public const string DEV_USER_EMAIL = 'admin@abribus.local';

    public const string DEV_USER_PASSWORD = 'abribus-admin';

    private const int FIXED_DEVICE_QUAY_COUNT = 3;

    public function __construct(
        private readonly DevelopmentQuaySelector $quaySelector,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'FIXTURES_DEVICE_TOKEN')]
        private readonly string $fixedDeviceToken,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Construits avant la purge : un jeton invalide arrête tout sans rien supprimer.
        $devUser = UserFactory::new([
            'email' => self::DEV_USER_EMAIL,
            'role' => Role::Admin,
            'password' => self::DEV_USER_PASSWORD,
        ])->withoutPersisting()->create();
        $fixedDevice = '' !== $this->fixedDeviceToken ? new Device($devUser, self::FIXED_DEVICE_LABEL, $this->fixedDeviceToken) : null;

        $this->removeDevices($manager);
        $this->replaceDevUser($manager, $devUser);

        if ($fixedDevice instanceof Device) {
            $this->loadFixedDevice($manager, $fixedDevice);
        } else {
            $this->logger->warning('FIXTURES_DEVICE_TOKEN vide : pas d\'appareil de développement. Le définir dans .env.local (voir docs/developpement.md).');
        }

        foreach (DeviceFactory::createMany(5, ['owner' => $devUser]) as $device) {
            $configCount = random_int(2, 4);

            for ($position = 0; $position < $configCount; ++$position) {
                DisplayConfigurationFactory::createOne([
                    'device' => $device,
                    'position' => $position,
                ]);
            }
        }
    }

    private function removeDevices(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Les fixtures nécessitent l\'ORM Doctrine.');
        }

        $manager->createQuery(\sprintf('DELETE FROM %s', DisplayConfiguration::class))->execute();
        $manager->createQuery(\sprintf('DELETE FROM %s', Device::class))->execute();
        $manager->clear();
    }

    /**
     * Recréé à chaque chargement : ses identifiants restent ceux de la documentation.
     * Les autres comptes (app:user:create) sont conservés. Il possède tous les
     * dispositifs de démonstration.
     */
    private function replaceDevUser(ObjectManager $manager, User $devUser): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Les fixtures nécessitent l\'ORM Doctrine.');
        }

        $manager->createQuery(\sprintf('DELETE FROM %s u WHERE u.email = :email', User::class))
            ->setParameter('email', self::DEV_USER_EMAIL)
            ->execute();

        $manager->persist($devUser);
        $manager->flush();
    }

    private function loadFixedDevice(ObjectManager $manager, Device $device): void
    {
        $selection = $this->quaySelector->select(self::FIXED_DEVICE_QUAY_COUNT);

        if (null !== $selection->fallbackReason) {
            $this->logger->warning(\sprintf('Quais de l\'appareil de développement : %s, complété par les quais desservis par le plus de lignes.', $selection->fallbackReason));
        }

        $manager->persist($device);

        foreach ($selection->quays as $position => $quay) {
            $configuration = new DisplayConfiguration($device, $quay)->setPosition($position);
            $device->addDisplayConfiguration($configuration);
            $manager->persist($configuration);
        }

        $manager->flush();

        $this->logger->notice(\sprintf(
            'Appareil de développement « %s » créé sur %s.',
            self::FIXED_DEVICE_LABEL,
            implode(', ', array_map(static fn (DisplayConfiguration $configuration): string => \sprintf('%s (%s)', $configuration->getQuay()->getName(), $configuration->getQuay()->getGtfsStopId()), $device->getDisplayConfigurations()->toArray())),
        ));
    }
}
