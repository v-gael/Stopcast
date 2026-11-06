<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use App\Tests\Support\WebTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Attribution des données GTFS exigée par la licence ODbL : producteur et
 * licence, sur une page publique plutôt que sur l'écran d'affichage.
 */
final class DataSourcesTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use WebTestTrait;

    public function testDataSourcesPageCitesTheProducerAndTheLicence(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/sources');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sources des données');
        self::assertSelectorTextContains('main', 'Données : TCAT – Troyes Champagne Métropole, licence ODbL, via transport.data.gouv.fr');
        $main = $crawler->filter('main');
        self::assertSame('https://transport.data.gouv.fr/datasets/donnees-tcat-troyes-champagne-metropole-1', $main->selectLink('TCAT – Troyes Champagne Métropole')->attr('href'));
        self::assertSame('https://opendatacommons.org/licenses/odbl/1.0/', $main->selectLink('licence ODbL')->attr('href'));
        self::assertSame('page', $this->footerNav($crawler)->selectLink('Sources des données')->attr('aria-current'));
        // Aucune session ouverte pour un simple visiteur.
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pagesWithAFooter(): iterable
    {
        yield 'page publique' => ['/', false];
        yield 'page connectée' => ['/devices', true];
    }

    #[DataProvider('pagesWithAFooter')]
    public function testFooterLinksToTheDataSources(string $path, bool $loggedIn): void
    {
        $client = self::createClient();
        if ($loggedIn) {
            $client->loginUser(UserFactory::createOne());
        }

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame('/sources', $this->footerNav($crawler)->selectLink('Sources des données')->attr('href'));
    }
}
