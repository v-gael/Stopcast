<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\User;
use App\Repository\DeviceRepository;

/**
 * Données d'un compte remises à son titulaire (droits d'accès et de
 * portabilité) : le compte, ses dispositifs et leurs configurations
 * d'affichage. Jamais le hash du mot de passe, le haché du secret d'un écran
 * ni les identifiants internes.
 */
final readonly class AccountDataExporter
{
    public function __construct(
        private DeviceRepository $deviceRepository,
    ) {
    }

    /**
     * @return array{
     *     exportedAt: string,
     *     account: array{email: string, role: string, createdAt: string, lastLoginAt: ?string},
     *     devices: list<array<string, mixed>>,
     * }
     */
    public function export(User $user, \DateTimeImmutable $exportedAt): array
    {
        return [
            'exportedAt' => $exportedAt->format(\DATE_ATOM),
            'account' => [
                'email' => $user->getEmail(),
                'role' => $user->getRole()->value,
                'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DATE_ATOM),
            ],
            'devices' => array_map(
                $this->device(...),
                $this->deviceRepository->findByOwnerWithConfigurationsAndQuays($user),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function device(Device $device): array
    {
        return [
            'label' => $device->getLabel(),
            'active' => $device->isActive(),
            'createdAt' => $device->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $device->getUpdatedAt()->format(\DATE_ATOM),
            'displayConfigurations' => array_map(
                $this->displayConfiguration(...),
                $device->getDisplayConfigurationsInRotationOrder(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function displayConfiguration(DisplayConfiguration $configuration): array
    {
        return [
            'quay' => [
                'name' => $configuration->getQuay()->getName(),
                'gtfsStopId' => $configuration->getQuay()->getGtfsStopId(),
            ],
            'label' => $configuration->getLabel(),
            'position' => $configuration->getPosition(),
            'active' => $configuration->isActive(),
            'createdAt' => $configuration->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $configuration->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
