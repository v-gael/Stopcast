<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Support\BrowserTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Échec volontaire, pour vérifier que la CI publie la capture de la page en
 * artefact. Branche jetable, jamais mergée.
 */
#[Group('browser')]
final class ScreenshotArtifactCheckTest extends PantherTestCase
{
    use BrowserTestTrait;

    public function testAConsoleErrorFailsWithAScreenshot(): void
    {
        $client = $this->anonymousClient();
        $this->visitWithCleanConsole($client, '/mentions-legales');

        $client->executeScript("console.error('Erreur volontaire : vérification de la capture en CI');");

        $this->assertNoBrowserErrors($client, 'erreur volontaire');
    }
}
