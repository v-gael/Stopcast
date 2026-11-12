<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Support\BrowserTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Fin d'un test navigateur : la page est quittée, pour qu'aucune requête
 * qu'elle lancerait encore (préchargement de Turbo, minuteur, iframe) ne
 * tombe sur la base que le test suivant réinitialise.
 */
#[Group('browser')]
final class BrowserTestIsolationTest extends PantherTestCase
{
    use BrowserTestTrait;

    public function testTheEndOfATestLeavesThePageAndItsPendingRequests(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/mentions-legales');
        // Requête en échec (404) partie après la dernière lecture de la console.
        $client->executeScript("setTimeout(() => fetch('/after-the-test'), 500);");

        $this->assertNoBrowserErrorsAtTheEnd();
        usleep(1_000_000);

        self::assertSame([], $client->getWebDriver()->manage()->getLog('browser'));
        self::assertSame('about:blank', $client->getCurrentURL());
    }
}
