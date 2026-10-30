<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Pages d'erreur de la prod : un noyau sans debug, comme en prod, rend les
 * templates de `templates/bundles/TwigBundle/Exception/` au lieu de la page
 * de debug.
 */
final class ErrorPagesTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testUnknownUrlRendersTheNotFoundPage(): void
    {
        $client = $this->createProductionClient();

        $crawler = $client->request('GET', '/page-inconnue');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorTextContains('main', 'Erreur 404');
        self::assertSame('/', $crawler->filter('main')->selectLink("Retour à l'accueil")->attr('href'));
    }

    public function testForbiddenPageRendersTheAccessDeniedPage(): void
    {
        $client = $this->createProductionClient();
        $client->loginUser(UserFactory::createOne());

        $crawler = $client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
        self::assertSame('/', $crawler->filter('main')->selectLink("Retour à l'accueil")->attr('href'));
    }

    public function testServerErrorRendersTheGenericPageWithoutDetails(): void
    {
        $client = $this->createProductionClient();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            // La page d'erreur est rendue par une sous-requête, qui ne doit pas échouer à son tour.
            if ($event->isMainRequest()) {
                throw new \RuntimeException('Détail interne à ne pas divulguer');
            }
        }, 1024);

        $crawler = $client->request('GET', '/');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextContains('h1', 'Une erreur est survenue');
        self::assertSelectorTextContains('main', 'Erreur 500');
        self::assertSame('/', $crawler->filter('main')->selectLink("Retour à l'accueil")->attr('href'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Détail interne', $content);
        self::assertStringNotContainsString('RuntimeException', $content);
    }

    public function testApiNotFoundStaysJson(): void
    {
        $client = $this->createProductionClient();

        $client->request('GET', '/api/inconnu', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/ld+json');
        $error = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame(404, $error['status']);
    }

    private function createProductionClient(): KernelBrowser
    {
        return self::createClient(['debug' => false]);
    }
}
