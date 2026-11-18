<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Enum\Role;
use App\Factory\UserFactory;
use App\Tests\Support\TurnstileSiteverifyMock;
use App\Tests\Support\WebTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Demande d'accès d'un visiteur, depuis le bouton de la page d'accueil :
 * enregistrée en base et signalée par mail aux administrateurs, jamais au
 * visiteur.
 */
#[ResetDatabase]
final class AccessRequestTest extends WebTestCase
{
    use WebTestTrait;

    private const string CONFIRMATION = 'Demande reçue. Si elle est retenue, vous recevrez une invitation par mail.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => 'admin@stopcast.test', 'role' => Role::Admin]);
        UserFactory::createOne(['email' => 'autre-admin@stopcast.test', 'role' => Role::Admin]);
        UserFactory::createOne(['email' => 'invite-admin@stopcast.test', 'role' => Role::Admin, 'password' => null]);
        UserFactory::createOne(['email' => 'lea@example.com']);
    }

    public function testHomeLinksToTheRequestForm(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->client->click($crawler->filter('main')->selectLink('Demander un accès')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demander un accès');
        self::assertSelectorExists('main a[href="/confidentialite"]');
    }

    public function testRequestFormCarriesTheTurnstileWidget(): void
    {
        $crawler = $this->client->request('GET', '/demande-acces');

        self::assertCount(1, $crawler->filter('main form [data-controller="turnstile"][data-turnstile-site-key-value="'.TurnstileSiteverifyMock::SITE_KEY.'"][data-turnstile-action-value="access-request"]'));
    }

    public function testRequestIsConfirmedAndSentToEveryActiveAdmin(): void
    {
        $this->requestAccess(' Paul@Example.com ', 'Recommandé par Léa.');

        self::assertResponseRedirects('/demande-acces/envoyee');
        // Un mail par administrateur activé : aucun ne voit l'adresse des autres.
        self::assertQueuedEmailCount(2);
        $emails = self::getMailerMessages();
        self::assertSame([['admin@stopcast.test'], ['autre-admin@stopcast.test']], array_map(
            static fn ($email): array => $email instanceof Email ? array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()) : [],
            $emails,
        ));
        foreach ($emails as $email) {
            self::assertEmailTextBodyContains($email, 'paul@example.com');
            self::assertEmailTextBodyContains($email, 'Recommandé par Léa.');
            self::assertEmailTextBodyContains($email, 'http://localhost/admin/users');
            self::assertEmailHtmlBodyContains($email, '>http://localhost/admin/users</p>');
        }

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', self::CONFIRMATION);
    }

    public function testRequestShowsUpForAnAdmin(): void
    {
        $this->requestAccess('paul@example.com', 'Recommandé par Léa.');

        $crawler = $this->requestsAsAdmin();

        self::assertSame([['paul@example.com', 'Recommandé par Léa.', date('d/m/Y')]], $this->accessRequestRows($crawler));
    }

    public function testMessageIsOptional(): void
    {
        $this->requestAccess('paul@example.com');

        self::assertResponseRedirects('/demande-acces/envoyee');
        self::assertQueuedEmailCount(2);
        self::assertSame([['paul@example.com', '—', date('d/m/Y')]], $this->accessRequestRows($this->requestsAsAdmin()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'email absent' => ['', '', "L'email est obligatoire."];
        yield 'email invalide' => ['paul@', '', "Cette adresse email n'est pas valide."];
        yield 'message trop long' => ['paul@example.com', str_repeat('a', 301), 'Le message ne doit pas dépasser 300 caractères.'];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestIsRefused(string $email, string $message, string $error): void
    {
        $this->requestAccess($email, $message);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', $error);
        self::assertQueuedEmailCount(0);
        self::assertSame([], $this->accessRequestRows($this->requestsAsAdmin()));
    }

    public function testFailedTurnstileIsRefused(): void
    {
        $this->requestAccess('paul@example.com', turnstileToken: TurnstileSiteverifyMock::INVALID_TOKEN);

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
        self::assertSame([], $this->accessRequestRows($this->requestsAsAdmin()));
    }

    /**
     * Même réponse qu'un email inconnu : la page ne dit pas qu'un compte
     * existe. Rien n'est enregistré ni envoyé.
     */
    public function testRequestForAnExistingAccountIsConfirmedButIgnored(): void
    {
        $this->requestAccess('paul@example.com');
        $this->client->followRedirect();
        $unknownAccountPage = $this->client->getCrawler()->filter('main')->text();

        $this->requestAccess('LEA@example.com', 'Je veux un deuxième compte.');

        self::assertResponseRedirects('/demande-acces/envoyee');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSame($unknownAccountPage, $this->client->getCrawler()->filter('main')->text());
        self::assertSame(['paul@example.com'], array_column($this->accessRequestRows($this->requestsAsAdmin()), 0));
    }

    public function testSecondRequestUpdatesTheFirst(): void
    {
        $this->requestAccess('paul@example.com', 'Premier message.');
        $this->requestAccess('paul@example.com');

        self::assertResponseRedirects('/demande-acces/envoyee');
        self::assertQueuedEmailCount(2);
        self::assertSame([['paul@example.com', '—', date('d/m/Y')]], $this->accessRequestRows($this->requestsAsAdmin()));
    }

    private function requestsAsAdmin(): Crawler
    {
        $this->client->loginUser($this->storedUser('admin@stopcast.test'));

        return $this->client->request('GET', '/admin/users');
    }
}
