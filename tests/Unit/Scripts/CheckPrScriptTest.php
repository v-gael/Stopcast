<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/check-pr, lancé comme le job « Titre de PR » de label-release-notes.yml :
 * titre et branches par l'environnement, tags sur l'entrée standard.
 */
final class CheckPrScriptTest extends TestCase
{
    private const array TAGS = ['v0.1.0', 'v0.2.0', 'v0.2.1', 'v0.10.0-rc.1', 'not-a-version'];

    /**
     * @param list<string> $tags
     */
    #[DataProvider('acceptedPullRequests')]
    public function testAcceptsAPullRequestThatFollowsTheReleaseCycle(string $base, string $head, string $title, array $tags = self::TAGS): void
    {
        $process = $this->check($base, $head, $title, $tags);

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertStringNotContainsString('::error', $process->getOutput());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3?: list<string>}>
     */
    public static function acceptedPullRequests(): iterable
    {
        yield 'feature vers develop' => ['develop', 'feature/display-themes', 'feat(display): add colour themes'];
        yield 'type sans scope' => ['develop', 'fix/login', 'fix: keep the session after a password change'];
        yield 'changement incompatible' => ['develop', 'feature/api', 'feat(api)!: drop the v1 endpoints'];
        yield 'type security' => ['develop', 'fix/csp', 'security: tighten the content security policy'];
        yield 'scope security' => ['develop', 'fix/csp', 'fix(security): escape the display name'];
        yield 'Dependabot Composer' => ['develop', 'dependabot/composer/symfony-8.1.3', 'chore(deps): bump symfony/http-kernel from 8.1.2 to 8.1.3'];
        yield 'Dependabot dev' => ['develop', 'dependabot/npm_and_yarn/eslint-9.1.0', 'chore(deps-dev): bump eslint from 9.0.0 to 9.1.0'];
        yield 'Dependabot actions' => ['develop', 'dependabot/github_actions/actions/checkout-5', 'ci: bump actions/checkout from 4 to 5'];
        yield 'revert de GitHub' => ['develop', 'revert-97-fix/new-screen-font', 'Revert "fix(display): load the new screen font"'];
        yield "revert d'un revert" => ['develop', 'revert-99-revert-97-fix/new-screen-font', 'Revert "Revert "fix(display): load the new screen font""'];
        yield 'retour de release' => ['develop', 'main', 'chore(release): merge v0.2.1 into develop'];
        yield 'release' => ['main', 'release/0.3.0', 'chore(release): v0.3.0'];
        yield 'release majeure' => ['main', 'release/1.0.0', 'chore(release): v1.0.0'];
        yield 'release patch' => ['main', 'release/0.2.2', 'chore(release): v0.2.2'];
        yield 'première release' => ['main', 'release/0.1.0', 'chore(release): v0.1.0', []];
        yield 'mineur à deux chiffres' => ['main', 'release/0.10.0', 'chore(release): v0.10.0', ['v0.9.3']];
        yield 'hotfix' => ['main', 'hotfix/0.2.2', 'fix(gtfs): ignore trips without a route'];
        yield 'hotfix sans scope' => ['main', 'hotfix/0.2.2', 'fix: ignore trips without a route'];
        yield 'hotfix sécurité' => ['main', 'hotfix/0.2.2', 'fix(security): reject forged display tokens'];
        yield 'hotfix qui saute un patch' => ['main', 'hotfix/0.2.5', 'fix: ignore trips without a route'];
        yield 'premier hotfix' => ['main', 'hotfix/0.1.1', 'fix: ignore trips without a route', []];
    }

    /**
     * @param list<string> $tags
     */
    #[DataProvider('rejectedPullRequests')]
    public function testRejectsAPullRequestAndStatesTheExpectedRule(string $base, string $head, string $title, string $rule, array $tags = self::TAGS): void
    {
        $process = $this->check($base, $head, $title, $tags);

        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringStartsWith('::error', $process->getOutput());
        self::assertStringContainsString($rule, $process->getOutput());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4?: list<string>}>
     */
    public static function rejectedPullRequests(): iterable
    {
        $conventional = 'Conventional Commits';

        yield 'titre de la branche' => ['develop', 'develop', 'Develop', $conventional];
        yield 'type inconnu' => ['develop', 'feature/x', 'feature: add colour themes', $conventional];
        yield 'type en majuscules' => ['develop', 'feature/x', 'Feat: add colour themes', $conventional];
        yield 'sans espace après les deux-points' => ['develop', 'feature/x', 'feat:add colour themes', $conventional];
        yield 'sans description' => ['develop', 'feature/x', 'feat: ', $conventional];
        yield 'scope vide' => ['develop', 'feature/x', 'feat(): add colour themes', $conventional];
        yield "revert d'un titre invalide" => ['develop', 'revert-93-develop', 'Revert "Develop"', $conventional];
        yield 'revert sans guillemets' => ['develop', 'revert-97-x', 'Revert fix: load the font', $conventional];
        yield 'retour de release mal titré' => ['develop', 'main', 'Merge main into develop', 'chore(release): merge vX.Y.Z into develop'];
        yield "retour d'une version non étiquetée" => ['develop', 'main', 'chore(release): merge v0.3.0 into develop', "v0.3.0 n'existe pas"];
        yield 'develop vers main' => ['main', 'develop', 'chore(release): v0.3.0', 'release/X.Y.Z ou hotfix/X.Y.Z'];
        yield 'feature vers main' => ['main', 'feature/x', 'feat: add colour themes', 'release/X.Y.Z ou hotfix/X.Y.Z'];
        yield 'revert vers main' => ['main', 'revert-97-x', 'Revert "fix: load the font"', 'release/X.Y.Z ou hotfix/X.Y.Z'];
        yield 'release mal titrée' => ['main', 'release/0.3.0', 'Release 0.3.0', 'chore(release): v0.3.0'];
        yield 'release dont le titre change de version' => ['main', 'release/0.3.0', 'chore(release): v0.3.1', 'chore(release): v0.3.0'];
        yield 'release préfixée par v' => ['main', 'release/v0.3.0', 'chore(release): v0.3.0', 'X.Y.Z'];
        yield 'release incomplète' => ['main', 'release/0.3', 'chore(release): v0.3', 'X.Y.Z'];
        yield 'release avec un zéro initial' => ['main', 'release/0.03.0', 'chore(release): v0.03.0', 'X.Y.Z'];
        yield 'release déjà étiquetée' => ['main', 'release/0.2.0', 'chore(release): v0.2.0', 'v0.2.0 existe déjà'];
        yield 'release plus petite' => ['main', 'release/0.1.5', 'chore(release): v0.1.5', 'plus grande que v0.2.1'];
        yield 'release comparée en nombres' => ['main', 'release/0.9.0', 'chore(release): v0.9.0', 'plus grande que v0.10.0', ['v0.10.0', 'v0.2.0']];
        yield 'hotfix mal titré' => ['main', 'hotfix/0.2.2', 'feat: ignore trips without a route', 'fix: …'];
        yield 'hotfix incompatible' => ['main', 'hotfix/0.2.2', 'fix!: ignore trips without a route', 'fix: …'];
        yield 'hotfix titré comme une release' => ['main', 'hotfix/0.2.2', 'chore(release): v0.2.2', 'fix: …'];
        yield 'hotfix déjà étiqueté' => ['main', 'hotfix/0.2.1', 'fix: ignore trips without a route', 'v0.2.1 existe déjà'];
        yield 'hotfix plus petit' => ['main', 'hotfix/0.1.1', 'fix: ignore trips without a route', 'plus grande que v0.2.1'];
        yield 'hotfix qui monte le mineur' => ['main', 'hotfix/0.3.0', 'fix: ignore trips without a route', 'seul le patch monte'];
        yield 'hotfix qui monte le majeur' => ['main', 'hotfix/1.2.2', 'fix: ignore trips without a route', 'seul le patch monte'];
    }

    public function testRejectsAMissingInput(): void
    {
        $process = $this->check('develop', '', 'feat: add colour themes', self::TAGS);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('PR_HEAD', $process->getErrorOutput());
    }

    /**
     * @param list<string> $tags
     */
    private function check(string $base, string $head, string $title, array $tags): Process
    {
        $process = new Process(
            [\dirname(__DIR__, 3).'/bin/check-pr'],
            env: ['PR_BASE' => $base, 'PR_HEAD' => $head, 'PR_TITLE' => $title],
            input: implode("\n", $tags),
        );
        $process->run();

        return $process;
    }
}
