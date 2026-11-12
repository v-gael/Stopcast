<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/release-version, lancé comme le job « Release » de ci.yml : message du
 * commit poussé sur main sur l'entrée standard, version en sortie.
 */
final class ReleaseVersionScriptTest extends TestCase
{
    #[DataProvider('releaseMerges')]
    public function testReadsTheVersionOfAMergedReleaseOrHotfix(string $message, string $version): void
    {
        $process = $this->read($message);

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertSame($version."\n", $process->getOutput());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function releaseMerges(): iterable
    {
        yield 'release' => ["Merge pull request #101 from v-gael/release/0.1.0\n\nchore(release): v0.1.0", '0.1.0'];
        yield 'hotfix' => ["Merge pull request #104 from v-gael/hotfix/0.1.1\n\nfix(gtfs): ignore trips without a route", '0.1.1'];
        yield 'nombres à plusieurs chiffres' => ["Merge pull request #2048 from v-gael/release/1.12.30\n\nchore(release): v1.12.30", '1.12.30'];
        yield 'sans corps' => ['Merge pull request #101 from v-gael/release/0.1.0', '0.1.0'];
        yield "branche d'un fork" => ["Merge pull request #101 from some-one/release/0.2.0\n\nchore(release): v0.2.0", '0.2.0'];
    }

    #[DataProvider('otherCommits')]
    public function testRejectsACommitThatIsNotAReleaseOrHotfixMerge(string $message): void
    {
        $process = $this->read($message);

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringStartsWith('::error::', $process->getOutput());
        self::assertStringContainsString('release/X.Y.Z ou hotfix/X.Y.Z', $process->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherCommits(): iterable
    {
        yield 'merge de develop' => ["Merge pull request #93 from v-gael/develop\n\nDevelop"];
        yield "merge d'une branche de travail" => ["Merge pull request #92 from v-gael/chore/release-process\n\nci(release): sort generated notes"];
        yield 'squash' => ['chore(release): v0.1.0 (#101)'];
        yield 'commit poussé directement' => ['fix: hot patch on main'];
        yield 'merge local' => ["Merge branch 'release/0.1.0'"];
        yield 'version préfixée par v' => ['Merge pull request #101 from v-gael/release/v0.1.0'];
        yield 'version incomplète' => ['Merge pull request #101 from v-gael/release/0.1'];
        yield 'zéro initial' => ['Merge pull request #101 from v-gael/release/0.01.0'];
        yield 'suffixe après la version' => ['Merge pull request #101 from v-gael/release/0.1.0-rc.1'];
        yield 'merge cité dans le corps' => ["fix: patch\n\nMerge pull request #101 from v-gael/release/0.1.0"];
        yield 'message vide' => [''];
    }

    private function read(string $message): Process
    {
        $process = new Process([\dirname(__DIR__, 3).'/bin/release-version'], input: $message);
        $process->run();

        return $process;
    }
}
