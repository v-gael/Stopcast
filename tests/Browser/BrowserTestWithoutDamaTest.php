<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Support\BrowserTestTrait;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Un test navigateur lancé sous DAMA échoue d'emblée, avec la commande à
 * lancer : sinon, ses données restées dans la transaction manqueraient au
 * serveur de Panther, loin de la cause.
 */
#[Group('browser')]
final class BrowserTestWithoutDamaTest extends PantherTestCase
{
    use BrowserTestTrait;

    public function testABrowserTestRefusesToRunUnderDama(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('php bin/phpunit -c phpunit.browser.dist.xml');

        StaticDriver::setKeepStaticConnections(true);

        try {
            $this->assertRunningWithoutDama();
        } finally {
            StaticDriver::setKeepStaticConnections(false);
        }
    }
}
