<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Vérifie que composer.json ne déclare que ce qui sert (bin/check, via make
// check). Chaque exception ci-dessous dit pourquoi l'usage ne se voit pas
// dans le code.
$config = new Configuration();

// Les bundles et le point d'entrée servent par config/bundles.php, public/ et
// bin/console, que l'autoload de composer.json ne couvre pas.
$config
    ->addPathsToScan([__DIR__.'/config', __DIR__.'/public', __DIR__.'/bin/console'], isDev: false)
    ->addPathToExclude(__DIR__.'/config/reference.php');

// Les composants Symfony (http-foundation, routing, security-core…) arrivent
// par les bundles qui les requièrent, comme le veut Flex : seule la dépendance
// déclarée et inutilisée compte ici, pas celle qui vient par un parent.
$config->ignoreErrors([ErrorType::SHADOW_DEPENDENCY]);

// Bundles de dev limités à dev et test dans bundles.php, et code des fixtures
// (chargé par doctrine:fixtures:load, jamais en prod).
$config->ignoreErrorsOnPaths([
    __DIR__.'/config/bundles.php',
    __DIR__.'/src/DataFixtures',
    __DIR__.'/src/Factory',
    __DIR__.'/src/Story',
], [ErrorType::DEV_DEPENDENCY_IN_PROD]);

// Utilisés en prod par la configuration, sans classe appelée dans src/ : le code
// ne les nomme que dans les tests.
$config->ignoreErrorsOnPackages([
    'symfony/asset-mapper', // importmap et assets compilés (asset_mapper.yaml)
    'symfony/dotenv',       // lecture de .env au démarrage (symfony/runtime)
    'symfony/http-client',  // client HTTP des flux GTFS et de Turnstile, derrière HttpClientInterface
    'symfony/lock',         // verrous des rate limiters sur Redis (lock.yaml)
    'symfony/yaml',         // lecture de config/*.yaml
], [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);

// Utilisés sans qu'aucune classe ne les nomme.
$config->ignoreErrorsOnPackages([
    'dragonmantank/cron-expression', // expressions de #[AsCronTask] (Scheduler)
    'symfony/asset',                 // fonction Twig asset()
    'symfony/doctrine-messenger',    // transports doctrine:// (messenger.yaml)
    'symfony/flex',                  // plugin Composer (recipes)
    'symfony/runtime',               // vendor/autoload_runtime.php, public/index.php et bin/console
    'symfony/translation',           // traductions françaises des messages intégrés de Symfony (validation, sécurité)
    'symfony/web-link',              // en-têtes Link de préchargement de l'importmap (ImportMapRenderer)
], [ErrorType::UNUSED_DEPENDENCY]);

$config->ignoreErrorsOnExtensions([
    'ext-ctype', // remplace symfony/polyfill-ctype (replace de composer.json)
    'ext-iconv', // remplace symfony/polyfill-iconv (replace de composer.json)
    'ext-redis', // cache, sessions et verrous sur Redis (REDIS_URL)
], [ErrorType::UNUSED_DEPENDENCY]);

return $config;
