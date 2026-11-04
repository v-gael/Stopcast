<?php

declare(strict_types=1);

namespace App\Tests\Integration\AssetMapper;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * Une CSS importée depuis un module JavaScript devient, dans l'importmap, un
 * module « data:application/javascript,… » que la CSP (script-src 'self')
 * bloque : tout l'import du module échoue, et le JavaScript de la page avec
 * lui. Les feuilles de style passent donc par un <link> dans les templates.
 */
final class NoCssImportedFromJavaScriptTest extends KernelTestCase
{
    public function testNoJavaScriptModuleImportsAStylesheet(): void
    {
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        \assert($assetMapper instanceof AssetMapperInterface);

        $cssImports = [];

        foreach ($assetMapper->allAssets() as $asset) {
            foreach ($asset->getJavaScriptImports() as $import) {
                if (str_ends_with($import->assetLogicalPath, '.css')) {
                    $cssImports[] = \sprintf('%s importe %s', $asset->logicalPath, $import->assetLogicalPath);
                }
            }
        }

        self::assertSame([], $cssImports, 'Une CSS importée en JavaScript est bloquée par la CSP et fait échouer le module : lier la feuille de style par un <link> dans le template.');
    }
}
