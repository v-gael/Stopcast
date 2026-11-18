<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * bin/check-docs, lancé comme bin/check le lance, sur un petit dépôt git
 * temporaire : ses fichiers sont ceux que liste git, suivis ou non ignorés.
 */
final class CheckDocsScriptTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir().'/check-docs-'.bin2hex(random_bytes(6));
        new Process(['git', 'init', '--quiet', $this->repository])->mustRun();
        $this->write('Makefile', "check: ## Contrôles\n\tbin/check\n\nup:\n\tdocker compose up -d\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->repository);
    }

    public function testAcceptsADocumentationWhoseReferencesExist(): void
    {
        $this->write('docs/developpement.md', "Lancer `make check`, puis :\n\n```sh\nmake up\n```\n");

        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
    }

    public function testRejectsAMissingMakeTargetWithItsFileAndLine(): void
    {
        $this->write('docs/developpement.md', "# Développement\n\nLancer `make chek` avant la PR.\n");

        $process = $this->check();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('docs/developpement.md:3: make chek', $process->getOutput());
    }

    public function testReadsMakeTargetsInCodeOnly(): void
    {
        $this->write('README.md', "Run `make check` to make sure the branch is ready.\n");

        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
    }

    public function testRejectsAMissingPathUnderARepositoryDirectory(): void
    {
        $this->write('src/Display/ScreenRenderer.php', "<?php\n");
        $this->write('README.md', "Le rendu est dans `src/Display/ScreenRender.php`.\n");

        $process = $this->check();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('README.md:1: src/Display/ScreenRender.php', $process->getOutput());
    }

    public function testChecksABareFileNameAgainstEveryFileOfTheRepository(): void
    {
        $this->write('.github/workflows/ci.yml', "on: push\n");
        $this->write('docs/exploitation.md', "Le workflow `ci.yml` déploie, puis `rollback.yml` revient en arrière.\n");

        $process = $this->check();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('docs/exploitation.md:1: rollback.yml', $process->getOutput());
        self::assertStringNotContainsString('ci.yml', $process->getOutput());
    }

    #[DataProvider('referencesOutsideTheRepository')]
    public function testIgnoresAReferenceThatNamesNoFileOfTheRepository(string $reference): void
    {
        $this->write('src/Kernel.php', "<?php\n");
        $this->write('var/.gitkeep', '');
        $this->write('docs/architecture.md', "Voir `$reference`.\n");

        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referencesOutsideTheRepository(): iterable
    {
        yield 'gabarit entre chevrons' => ['src/<context>/docs/adr/'];
        yield 'points de suspension' => ['src/…'];
        yield 'gabarit entre accolades' => ['src/{Display,Gtfs}/'];
        yield 'motif' => ['src/*.php'];
        yield 'version' => ['src/X.Y.Z/notes.md'];
        yield 'gabarit de date' => ['stopcast-donnees-AAAA-MM-JJ.json'];
        yield 'dossier var' => ['var/log/test.log'];
        yield 'serveur' => ['/srv/stopcast/.env.prod.local'];
        yield 'conteneur' => ['/src/Gone.php'];
        yield 'dossier personnel' => ['~/.ssh/config'];
        yield 'URL' => ['https://github.com/v-gael/stopcast/blob/main/src/Gone.php'];
        yield 'autre dossier' => ['Europe/Paris'];
        yield 'domaine' => ['api.github.com'];
    }

    #[DataProvider('pathsWithASuffix')]
    public function testChecksAPathWithoutItsLineOrAnchor(string $reference): void
    {
        $this->write('src/Kernel.php', "<?php\n");
        $this->write('docs/architecture.md', "Voir `$reference`.\n");

        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsWithASuffix(): iterable
    {
        yield 'ligne' => ['src/Kernel.php:12'];
        yield 'ancre' => ['docs/architecture.md#affichage'];
    }

    public function testRejectsAMissingPathDespiteItsLine(): void
    {
        $this->write('src/Kernel.php', "<?php\n");
        $this->write('docs/architecture.md', "Voir `src/Gone.php:12` et `./src/Gone.php`.\n");

        $process = $this->check();

        self::assertSame([
            'docs/architecture.md:1: src/Gone.php:12 : chemin absent du dépôt',
            'docs/architecture.md:1: ./src/Gone.php : chemin absent du dépôt',
        ], explode("\n", trim($process->getOutput())));
    }

    public function testReadsAFenceInsideALongerFenceAsCode(): void
    {
        $this->write('docs/developpement.md', implode("\n", [
            '````md', '```sh', 'make gone', '```', 'make lost', '````', 'Puis `make vanished`.', '',
        ]));

        $process = $this->check();

        self::assertSame([
            'docs/developpement.md:3: make gone : cible absente du Makefile',
            'docs/developpement.md:5: make lost : cible absente du Makefile',
            'docs/developpement.md:7: make vanished : cible absente du Makefile',
        ], explode("\n", trim($process->getOutput())));
    }

    public function testReadsEveryTargetOfAMakefileRule(): void
    {
        $this->write('Makefile', "up start: ## Démarrer\n\tdocker compose up -d\n");
        $this->write('README.md', "`make up` ou `make start`.\n");

        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
    }

    public function testFailsWithoutAMakefile(): void
    {
        new Filesystem()->remove($this->repository.'/Makefile');
        $this->write('README.md', "`make up`.\n");

        $process = $this->check();

        self::assertSame(2, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('Makefile', $process->getErrorOutput());
    }

    public function testRejectsAMissingClassOfTheProject(): void
    {
        $this->write('src/Display/ScreenPaginator.php', "<?php\n\nnamespace App\\Display;\n\nfinal readonly class ScreenPaginator {}\n");
        $this->write('tests/Support/WebTestTrait.php', "<?php\n\nnamespace App\\Tests\\Support;\n\ntrait WebTestTrait {}\n");
        $this->write('docs/architecture.md', implode("\n", [
            '`App\Display\ScreenPaginator` découpe, `App\Display` assemble.',
            '`App\Tests\Support\WebTestTrait` et `WebTestTrait::logIn()` connectent.',
            '`App\Display\ScreenPager` a disparu.',
            '`App\Tests\Support\WebTestTraits` aussi, comme `DisplayTestTrait` et `ScreenContactListener`.',
            '',
        ]));

        $process = $this->check();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertSame([
            'docs/architecture.md:3: App\Display\ScreenPager : classe absente de src/ et tests/',
            'docs/architecture.md:4: App\Tests\Support\WebTestTraits : classe absente de src/ et tests/',
            'docs/architecture.md:4: DisplayTestTrait : classe absente de src/ et tests/',
            'docs/architecture.md:4: ScreenContactListener : classe absente de src/ et tests/',
        ], explode("\n", trim($process->getOutput())));
    }

    public function testRejectsARelativeLinkToAMissingFile(): void
    {
        $this->write('CODING_STANDARDS.md', "# Normes\n");
        $this->write('docs/exploitation.md', "# Exploitation\n");
        $this->write('docs/adr/0009-migrations.md', implode("\n", [
            'Voir [les normes](../../CODING_STANDARDS.md), [le déploiement](../exploitation.md#déploiement), [plus haut](#contexte) et [Symfony](https://symfony.com/doc).',
            "Voir [la décision 0010](0010-migrations-suite.md), [la prod](../production.md#serveur) et [l'exploitation](<../exploitation.md>).",
            '',
        ]));

        $process = $this->check();

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertSame([
            'docs/adr/0009-migrations.md:2: 0010-migrations-suite.md : lien vers un fichier absent',
            'docs/adr/0009-migrations.md:2: ../production.md#serveur : lien vers un fichier absent',
        ], explode("\n", trim($process->getOutput())));
    }

    private function write(string $path, string $content): void
    {
        new Filesystem()->dumpFile($this->repository.'/'.$path, $content);
    }

    private function check(): Process
    {
        $process = new Process([\dirname(__DIR__, 3).'/bin/check-docs', $this->repository]);
        $process->run();

        return $process;
    }
}
