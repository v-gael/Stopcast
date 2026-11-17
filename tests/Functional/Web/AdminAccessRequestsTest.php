<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Enum\Role;
use App\Factory\AccessRequestFactory;
use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Address;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Demandes d'accès dans la liste des comptes : l'administrateur invite ou
 * supprime chacune.
 */
#[ResetDatabase]
final class AdminAccessRequestsTest extends WebTestCase
{
    use WebTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->loginUser(UserFactory::createOne(['email' => 'admin@stopcast.test', 'role' => Role::Admin]));
    }

    public function testSectionIsHiddenWithoutRequests(): void
    {
        $this->client->request('GET', '/admin/users');

        self::assertSelectorNotExists('#demandes-acces');
    }

    public function testRequestsAreListedNewestFirst(): void
    {
        AccessRequestFactory::createOne(['email' => 'ancienne@example.com', 'requestedAt' => new \DateTimeImmutable('2026-11-01 10:00')]);
        AccessRequestFactory::createOne(['email' => 'recente@example.com', 'message' => 'Recommandé par Léa.', 'requestedAt' => new \DateTimeImmutable('2026-11-15 10:00')]);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertSelectorTextContains('#demandes-acces h2', "Demandes d'accès (2)");
        self::assertSame([
            ['recente@example.com', 'Recommandé par Léa.', '15/11/2026'],
            ['ancienne@example.com', '—', '01/11/2026'],
        ], $this->accessRequestRows($crawler));
    }

    public function testInvitingARequestCreatesAPendingAccountAndRemovesIt(): void
    {
        AccessRequestFactory::createOne(['email' => 'paul@example.com']);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('#demandes-acces')->selectButton('Inviter paul@example.com')->form());

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(1);
        self::assertSame(['paul@example.com'], array_map(static fn (Address $address): string => $address->getAddress(), $this->lastEmail()->getTo()));
        $user = $this->storedUser('paul@example.com');
        self::assertTrue($user->isPending());
        self::assertSame(Role::User, $user->getRole());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invitation envoyée à paul@example.com.');
        self::assertSame([], $this->accessRequestRows($crawler));
    }

    public function testInvitingARequestWhoseAddressHasAnAccountOnlyRemovesIt(): void
    {
        AccessRequestFactory::createOne(['email' => 'paul@example.com']);
        UserFactory::createOne(['email' => 'paul@example.com']);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('#demandes-acces')->selectButton('Inviter paul@example.com')->form());

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Un compte existe déjà pour paul@example.com : la demande est supprimée.');
        self::assertSame([], $this->accessRequestRows($crawler));
    }

    public function testDeletingARequestSendsNothing(): void
    {
        AccessRequestFactory::createOne(['email' => 'paul@example.com']);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('#demandes-acces')->selectButton('Supprimer la demande de paul@example.com')->form());

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'La demande de paul@example.com a été supprimée.');
        self::assertSame([], $this->accessRequestRows($crawler));
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'paul@example.com']));
    }

    public function testInvitationFormRemovesTheRequestOfTheSameAddress(): void
    {
        AccessRequestFactory::createOne(['email' => 'paul@example.com']);
        AccessRequestFactory::createOne(['email' => 'autre@example.com']);

        $crawler = $this->client->request('GET', '/admin/users/invite');
        $this->client->submit($crawler->selectButton("Envoyer l'invitation")->form([
            'invite_user[email]' => 'Paul@Example.com',
            'invite_user[role]' => Role::User->value,
        ]));

        self::assertResponseRedirects('/admin/users');
        self::assertSame(['autre@example.com'], array_column($this->accessRequestRows($this->client->followRedirect()), 0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'inviter' => ['invite'];
        yield 'supprimer' => ['delete'];
    }

    #[DataProvider('actions')]
    public function testActionsNeedAValidCsrfToken(string $action): void
    {
        $accessRequest = AccessRequestFactory::createOne(['email' => 'paul@example.com']);

        $this->client->request('POST', \sprintf('/admin/users/access-requests/%d/%s', $accessRequest->getId(), $action), ['_token' => 'invalide']);

        // Jeton CSRF invalide : Security renvoie vers la connexion.
        self::assertResponseRedirects('http://localhost/');
        self::assertQueuedEmailCount(0);
        self::assertInstanceOf(AccessRequest::class, $this->entityManager()->find(AccessRequest::class, $accessRequest->getId()));
    }

    #[DataProvider('actions')]
    public function testActionsAreForbiddenToAUser(string $action): void
    {
        $accessRequest = AccessRequestFactory::createOne(['email' => 'paul@example.com']);
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/devices');

        $this->client->request('POST', \sprintf('/admin/users/access-requests/%d/%s', $accessRequest->getId(), $action), [
            '_token' => $this->csrfToken(\sprintf('%s-access-request-%d', $action, $accessRequest->getId())),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertQueuedEmailCount(0);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        return $entityManager;
    }
}
