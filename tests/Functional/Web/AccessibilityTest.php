<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use App\Enum\Role;
use App\Factory\DeviceFactory;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Points du WCAG 2.2 vérifiables dans le HTML rendu (voir docs/accessibilite.md) :
 * lien d'évitement, titres, champs reliés à leur libellé et à leurs erreurs.
 */
final class AccessibilityTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    private KernelBrowser $client;

    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->admin = UserFactory::createOne(['email' => 'admin@example.com', 'role' => Role::Admin]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function layouts(): iterable
    {
        yield 'public' => ['/', false];
        yield 'espace' => ['/devices', true];
    }

    #[DataProvider('layouts')]
    public function testSkipLinkIsTheFirstLinkAndLeadsToTheMainContent(string $path, bool $loggedIn): void
    {
        if ($loggedIn) {
            $this->client->loginUser($this->admin);
        }

        $crawler = $this->client->request('GET', $path);

        $skipLink = $crawler->filter('body a')->first();
        self::assertSame('Aller au contenu', $skipLink->text());
        self::assertSame('#contenu', $skipLink->attr('href'));
        // Masqué hors focus, visible au focus clavier.
        self::assertStringContainsString('sr-only', (string) $skipLink->attr('class'));
        self::assertStringContainsString('focus:not-sr-only', (string) $skipLink->attr('class'));
        self::assertCount(1, $crawler->filter('main#contenu'));
    }

    public function testPublicPagesHaveOneMainHeadingAndNoSkippedLevel(): void
    {
        $this->assertHeadingOutline('/');
        $this->assertHeadingOutline('/reset-password');

        $this->client->submit($this->client->getCrawler()->selectButton('Envoyer le lien')->form([
            'reset_password_request[email]' => $this->admin->getEmail(),
        ]));
        $link = '/reset-password/reset/'.$this->tokenFromEmail('#http://localhost/reset-password/reset/(\S+)#');
        $this->assertHeadingOutline((string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', $link);
        $this->assertHeadingOutline((string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testInvitationPageHasOneMainHeading(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users/invite');
        $this->client->submit($crawler->selectButton("Envoyer l'invitation")->form([
            'invite_user[email]' => 'lea@example.com',
            'invite_user[role]' => Role::User->value,
        ]));
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/invitation/'.$this->tokenFromEmail('#http://localhost/invitation/(\S+)#'));
        $this->assertHeadingOutline((string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSpacePagesHaveOneMainHeadingAndNoSkippedLevel(): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->admin]);
        $this->client->loginUser($this->admin);

        foreach (['/devices', \sprintf('/devices/%d', $device->getId()), '/devices/pair?code=K7F3QX', \sprintf('/display-configurations/new?device=%d', $device->getId()), '/account', '/admin/users'] as $path) {
            $this->assertHeadingOutline($path);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formPages(): iterable
    {
        yield 'connexion' => ['/'];
        yield 'mot de passe oublié' => ['/reset-password'];
        yield 'ajout de dispositif' => ['/devices/new'];
        yield 'modification de dispositif' => ['/devices/%device%/edit'];
        yield 'appairage par QR code' => ['/devices/pair?code=K7F3QX'];
        yield 'ajout de quai' => ['/display-configurations/new?device=%device%'];
        yield 'changement de mot de passe' => ['/account/password'];
        yield 'suppression de compte' => ['/account/delete'];
        yield 'invitation' => ['/admin/users/invite'];
        yield 'modification de compte' => ['/admin/users/%user%/edit'];
    }

    #[DataProvider('formPages')]
    public function testEveryFieldHasALabel(string $path): void
    {
        $device = DeviceFactory::createOne(['owner' => $this->admin]);
        $user = UserFactory::createOne();
        if ('/' !== $path && '/reset-password' !== $path) {
            $this->client->loginUser($this->admin);
        }

        $crawler = $this->client->request('GET', strtr($path, ['%device%' => $device->getId(), '%user%' => $user->getId()]));

        self::assertResponseIsSuccessful();
        $fields = $crawler->filter('main form')->filter('input:not([type="hidden"]), select, textarea');
        self::assertGreaterThan(0, $fields->count());
        $fields->each(static function (Crawler $field) use ($crawler): void {
            $id = (string) $field->attr('id');
            self::assertNotSame('', $id, 'Champ sans id : '.$field->attr('name'));
            self::assertCount(1, $crawler->filter(\sprintf('label[for="%s"]', $id)), 'Champ sans libellé : '.$id);
        });
    }

    public function testRadioGroupIsNamedByTheLabelOfTheField(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $user->getId()));

        $group = $crawler->filter('main form [role="radiogroup"]');
        self::assertCount(1, $group);
        self::assertSame('Rôle', $crawler->filter('#'.$group->attr('aria-labelledby'))->text());
        self::assertCount(2, $group->filter('input[type="radio"]'));
    }

    public function testFieldInErrorIsLinkedToItsHelpAndItsErrors(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/devices/new');

        $crawler = $this->client->submit($crawler->selectButton('Créer')->form(['device[label]' => '']));

        self::assertResponseStatusCodeSame(422);
        $input = $crawler->filter('#device_label');
        self::assertCount(1, $crawler->filter('label[for="device_label"]'));
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertSame(['device_label_help', 'device_label_error1'], $this->describedBy($crawler, $input));
        self::assertSelectorTextContains('#device_label_error1', 'Cette valeur ne doit pas être vide.');
    }

    public function testRepeatedFieldInErrorIsLinkedToItsErrors(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/account/password');

        $crawler = $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'change_password[currentPassword]' => UserFactory::DEFAULT_PASSWORD,
            'change_password[newPassword][first]' => 'court',
            'change_password[newPassword][second]' => 'court',
        ]));

        self::assertResponseStatusCodeSame(422);
        $input = $crawler->filter('#change_password_newPassword_first');
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertSame(['change_password_newPassword_first_help', 'change_password_newPassword_first_error1'], $this->describedBy($crawler, $input));
        self::assertNull($crawler->filter('#change_password_currentPassword')->attr('aria-invalid'));
    }

    /**
     * Un seul h1 par page, et aucun niveau sauté en descendant (h2 puis h4).
     */
    private function assertHeadingOutline(string $path): void
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $levels = $crawler->filter('h1, h2, h3, h4, h5, h6')->each(static fn (Crawler $heading): int => (int) substr($heading->nodeName(), 1));
        self::assertSame(1, $levels[0] ?? null, 'La page ne commence pas par un h1 : '.$path);
        self::assertCount(1, array_keys($levels, 1), 'Plusieurs h1 : '.$path);
        foreach (\array_slice($levels, 1) as $index => $level) {
            self::assertLessThanOrEqual($levels[$index] + 1, $level, 'Niveau de titre sauté : '.$path);
        }
    }

    /**
     * Identifiants référencés par « aria-describedby », chacun présent dans la page.
     *
     * @return list<string>
     */
    private function describedBy(Crawler $crawler, Crawler $field): array
    {
        $ids = explode(' ', (string) $field->attr('aria-describedby'));
        foreach ($ids as $id) {
            self::assertCount(1, $crawler->filter('#'.$id), 'Référence absente : '.$id);
        }

        return $ids;
    }
}
