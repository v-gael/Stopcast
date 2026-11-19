<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * bin/check-env, lancé par bin/prod-verify avant le déploiement local : un
 * fichier d'environnement comparé à son modèle, par les noms seulement.
 */
final class CheckEnvScriptTest extends TestCase
{
    private const string EXAMPLE = <<<'ENV'
        # Modèle
        APP_SECRET=            # à générer
        POSTGRES_USER=app
        REDIS_PASSWORD=modèle-de-test
        MAILER_DSN=
        ENV;

    private const string SCRIPT = __DIR__.'/../../../bin/check-env';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/check-env-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testPassesWhenEveryVariableOfTheExampleIsSet(): void
    {
        $process = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\nREDIS_PASSWORD=s3cr3t-redis\nMAILER_DSN=smtp://u:s3cr3t-mail@host\nEXTRA=1\n");

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testListsEveryMissingVariableAtOnce(): void
    {
        $process = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\n");

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('REDIS_PASSWORD', $process->getErrorOutput());
        self::assertStringContainsString('MAILER_DSN', $process->getErrorOutput());
        self::assertStringNotContainsString('APP_SECRET', $process->getErrorOutput());
    }

    public function testNeverPrintsAValue(): void
    {
        $process = $this->check("APP_SECRET=s3cr3t-app\nREDIS_PASSWORD=s3cr3t-redis\n# MAILER_DSN=s3cr3t-commented\n");

        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('s3cr3t', $output);
        self::assertStringNotContainsString('modèle-de-test', $output);
    }

    public function testAcceptsAVariableSetInTheEnvironmentInstead(): void
    {
        $process = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\n", ['REDIS_PASSWORD' => 'from-env', 'MAILER_DSN' => 'null://null']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('from-env', $process->getOutput().$process->getErrorOutput());
    }

    public function testOnlyVariablesRequiredByComposeBlockWhenComposeFilesAreGiven(): void
    {
        $compose = $this->write('compose.yaml', <<<'YAML'
            services:
              app:
                environment:
                  REDIS_URL: redis://${REDIS_PASSWORD:?REDIS_PASSWORD est requis}@redis
                  MAILER_DSN: ${MAILER_DSN:-}
            YAML);

        $optionalOnly = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\nREDIS_PASSWORD=s3cr3t-redis\n", compose: [$compose]);
        self::assertSame(0, $optionalOnly->getExitCode(), $optionalOnly->getErrorOutput());
        self::assertStringContainsString('MAILER_DSN', $optionalOnly->getErrorOutput());

        $requiredMissing = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\n", compose: [$compose]);
        self::assertSame(1, $requiredMissing->getExitCode());
        self::assertStringContainsString('REDIS_PASSWORD', $requiredMissing->getErrorOutput());
    }

    public function testARequiredVariableLeftEmptyBlocksLikeAMissingOne(): void
    {
        $compose = $this->write('compose.yaml', 'REDIS_URL: redis://${REDIS_PASSWORD:?requis}@redis');

        $process = $this->check("APP_SECRET=s3cr3t-app\nPOSTGRES_USER=app\nREDIS_PASSWORD=      # à remplir\nMAILER_DSN=\n", compose: [$compose]);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('REDIS_PASSWORD', $process->getErrorOutput());
        self::assertStringNotContainsString('MAILER_DSN', $process->getErrorOutput());
    }

    public function testMalformedLinesNeverLeakTheirValue(): void
    {
        $process = $this->check("export APP_SECRET=s3cr3t-export\n  POSTGRES_USER=s3cr3t-indented\nREDIS_PASSWORD=s3cr3t-crlf\r\nMAILER_DSN s3cr3t-no-equal\n");

        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('s3cr3t', $output);
    }

    public function testFailsWhenTheExampleDefinesNoVariable(): void
    {
        $process = $this->runScript([$this->write('env', "APP_SECRET=s3cr3t-app\n"), $this->write('example', "# rien\n")]);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('example', $process->getErrorOutput());
    }

    public function testFailsWhenTheFileIsMissing(): void
    {
        $process = $this->runScript([$this->directory.'/absent', $this->write('example', self::EXAMPLE)]);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('absent', $process->getErrorOutput());
    }

    /**
     * @param array<string, string> $environment
     * @param list<string>          $compose
     */
    private function check(string $content, array $environment = [], array $compose = []): Process
    {
        return $this->runScript([$this->write('env', $content), $this->write('example', self::EXAMPLE), ...$compose], $environment);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function runScript(array $arguments, array $environment = []): Process
    {
        $process = new Process(
            [self::SCRIPT, ...$arguments],
            env: $environment + ['REDIS_PASSWORD' => false, 'MAILER_DSN' => false, 'APP_SECRET' => false, 'POSTGRES_USER' => false],
        );
        $process->run();

        return $process;
    }

    private function write(string $name, string $content): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }
}
