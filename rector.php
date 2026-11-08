<?php

declare(strict_types=1);

use Rector\Arguments\Rector\ClassMethod\ReplaceArgumentDefaultValueRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/bin',
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true
    )
    ->withComposerBased(
        doctrine: true,
        symfony: true,
    )
    ->withSkip([
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Remplace Cookie::SAMESITE_STRICT par lui-même, en nom complet, à
        // chaque passage : PHP CS Fixer le réimporte, et les deux bouclent.
        ReplaceArgumentDefaultValueRector::class => [__DIR__.'/src/Display/Pairing/DisplayCookies.php'],
        __DIR__.'/bin/console',
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/preload.php',
        __DIR__.'/public/index.php',
        __DIR__.'/src/Kernel.php',
        __DIR__.'/tests/bootstrap.php',
    ])
;
