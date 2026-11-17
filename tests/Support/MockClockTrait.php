<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Horloge de l'application arrêtée à une heure donnée, dans le fuseau du
 * conteneur : le test l'avance avec sleep().
 *
 * @phpstan-require-extends KernelTestCase
 */
trait MockClockTrait
{
    private function mockClock(string $now): MockClock
    {
        $clock = new MockClock($now, date_default_timezone_get());
        self::getContainer()->set('clock', $clock);

        return $clock;
    }
}
