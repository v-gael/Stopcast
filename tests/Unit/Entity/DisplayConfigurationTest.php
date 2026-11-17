<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Device;
use App\Entity\DisplayConfiguration;
use App\Entity\Quay;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisplayConfigurationTest extends TestCase
{
    public function testDisplayNameIsTheLabel(): void
    {
        self::assertSame('Direction travail', $this->configuration('Direction travail')->getDisplayName());
    }

    #[DataProvider('blankLabels')]
    public function testDisplayNameFallsBackToTheQuayNameWithoutLabel(?string $label): void
    {
        self::assertSame('CENTRE VILLE', $this->configuration($label)->getDisplayName());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function blankLabels(): iterable
    {
        yield 'aucun libellé' => [null];
        yield 'libellé vide' => [''];
        yield "libellé fait d'espaces" => ['   '];
    }

    public function testLabelZeroIsKept(): void
    {
        self::assertSame('0', $this->configuration('0')->getDisplayName());
    }

    private function configuration(?string $label): DisplayConfiguration
    {
        $device = new Device(new User('owner@example.com'), 'Salon');

        return new DisplayConfiguration($device, new Quay('S1', 'CENTRE VILLE', 38.5, -28.6, 'P1'))->setLabel($label);
    }
}
