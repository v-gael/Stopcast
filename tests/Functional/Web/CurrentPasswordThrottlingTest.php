<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\VarDumper\Cloner\Data;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Limite d'essais du mot de passe actuel, partagée entre le changement de mot
 * de passe et la suppression du compte : 5 essais faux par compte.
 */
final class CurrentPasswordThrottlingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'lea@example.com';

    private const string WRONG_PASSWORD = 'mauvais-mot-de-passe';

    private const string NEW_PASSWORD = 'nouveau-mot-de-passe';

    private const string INCORRECT = 'Le mot de passe actuel est incorrect.';

    private const string TOO_MANY_ATTEMPTS = "Trop d'essais. Réessayez plus tard.";

    private KernelBrowser $client;

    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le compteur vit en mémoire dans le conteneur : il doit survivre
        // d'une requête à l'autre.
        $this->client->disableReboot();

        $this->user = UserFactory::createOne(['email' => self::EMAIL]);
        $this->client->loginUser($this->user);
    }

    public function testChangePasswordIsBlockedAfterFiveWrongAttempts(): void
    {
        $this->assertWrongAttemptsAreRefused(5, 'change_password');

        $this->changePassword(UserFactory::DEFAULT_PASSWORD, profile: true);

        $this->assertBlocked();
        $this->assertWarningLogged('change_password');
        UserFactory::assert()->exists(['email' => self::EMAIL]);
        self::assertTrue($this->passwordIs(UserFactory::DEFAULT_PASSWORD));
    }

    public function testDeleteAccountIsBlockedAfterFiveWrongAttempts(): void
    {
        $this->assertWrongAttemptsAreRefused(5, 'delete_account');

        $this->deleteAccount(UserFactory::DEFAULT_PASSWORD, profile: true);

        $this->assertBlocked();
        $this->assertWarningLogged('delete_account');
        UserFactory::assert()->exists(['email' => self::EMAIL]);
        self::assertQueuedEmailCount(0);
    }

    public function testBothFormsShareTheCounter(): void
    {
        $this->assertWrongAttemptsAreRefused(3, 'change_password');
        $this->assertWrongAttemptsAreRefused(2, 'delete_account');

        $this->deleteAccount(self::WRONG_PASSWORD);

        $this->assertBlocked();
    }

    public function testTheCounterIsPerAccount(): void
    {
        $this->assertWrongAttemptsAreRefused(5, 'change_password');
        $this->changePassword(self::WRONG_PASSWORD);
        $this->assertBlocked();

        $this->client->getCookieJar()->clear();
        $this->client->loginUser(UserFactory::createOne(['email' => 'max@example.com']));
        $this->changePassword(self::WRONG_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', self::INCORRECT);
    }

    public function testCorrectPasswordResetsTheCounter(): void
    {
        $this->assertWrongAttemptsAreRefused(4, 'change_password');

        $this->changePassword(UserFactory::DEFAULT_PASSWORD, self::NEW_PASSWORD);
        self::assertResponseRedirects('/account', 303);

        $this->assertWrongAttemptsAreRefused(5, 'change_password');
        $this->changePassword(self::NEW_PASSWORD);
        $this->assertBlocked();
    }

    public function testMismatchedConfirmationDoesNotConsumeAnAttempt(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->changePassword(UserFactory::DEFAULT_PASSWORD, self::NEW_PASSWORD, 'autre-mot-de-passe');
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main form', 'Les deux mots de passe ne correspondent pas.');
        }

        $this->assertWrongAttemptsAreRefused(5, 'change_password');
        $this->changePassword(self::WRONG_PASSWORD);
        $this->assertBlocked();
    }

    public function testSessionStaysOpenWhenBlocked(): void
    {
        $this->assertWrongAttemptsAreRefused(5, 'delete_account');
        $this->deleteAccount(self::WRONG_PASSWORD);
        $this->assertBlocked();

        $this->client->request('GET', '/devices');

        self::assertResponseIsSuccessful();
    }

    /**
     * Envoie le formulaire avec un mot de passe actuel faux, $count fois.
     */
    private function assertWrongAttemptsAreRefused(int $count, string $form): void
    {
        for ($i = 0; $i < $count; ++$i) {
            'change_password' === $form ? $this->changePassword(self::WRONG_PASSWORD) : $this->deleteAccount(self::WRONG_PASSWORD);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main form', self::INCORRECT);
            self::assertSelectorTextNotContains('main form', self::TOO_MANY_ATTEMPTS);
        }
    }

    private function assertBlocked(): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', self::TOO_MANY_ATTEMPTS);
        self::assertSelectorTextNotContains('main form', self::INCORRECT);
    }

    private function assertWarningLogged(string $form): void
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('logger');
        self::assertInstanceOf(LoggerDataCollector::class, $collector);

        $warnings = [];
        foreach ($collector->getProcessedLogs() as $log) {
            self::assertIsArray($log);
            if ('WARNING' === $log['priorityName'] && 'app' === $log['channel']) {
                self::assertInstanceOf(Data::class, $log['context']);
                $warnings[] = $log['context']->getValue(true);
            }
        }

        self::assertSame([['account' => self::EMAIL, 'form' => $form]], $warnings);
    }

    private function changePassword(string $current, string $new = self::NEW_PASSWORD, ?string $confirmation = null, bool $profile = false): void
    {
        $crawler = $this->client->request('GET', '/account/password');
        self::assertResponseIsSuccessful();

        if ($profile) {
            $this->client->enableProfiler();
        }

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'change_password[currentPassword]' => $current,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $confirmation ?? $new,
        ]));
    }

    private function deleteAccount(string $password, bool $profile = false): void
    {
        $crawler = $this->client->request('GET', '/account/delete');
        self::assertResponseIsSuccessful();

        if ($profile) {
            $this->client->enableProfiler();
        }

        $this->client->submit($crawler->selectButton('Supprimer mon compte')->form([
            'delete_account[currentPassword]' => $password,
        ]));
    }

    private function passwordIs(string $password): bool
    {
        $user = UserFactory::repository()->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        return $passwordHasher->isPasswordValid($user, $password);
    }
}
