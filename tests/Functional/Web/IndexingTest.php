<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class IndexingTest extends WebTestCase
{
    public function testHomeIsIndexedAndSharedWithOpenGraph(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertSame($crawler->filter('title')->text(), $this->openGraph($crawler, 'title'));
        self::assertSame($crawler->filter('meta[name="description"]')->attr('content'), $this->openGraph($crawler, 'description'));
        self::assertSame('http://localhost/', $this->openGraph($crawler, 'url'));
        self::assertSame('website', $this->openGraph($crawler, 'type'));
        self::assertSame('Stopcast', $this->openGraph($crawler, 'site_name'));
        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertSame('/favicon.svg', $crawler->filter('link[rel="icon"]')->attr('href'));
    }

    public function testOtherPublicPagesAreNotIndexed(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame($crawler->filter('title')->text(), $this->openGraph($crawler, 'title'));
        self::assertSame('http://localhost/reset-password', $this->openGraph($crawler, 'url'));
    }

    private function openGraph(Crawler $crawler, string $property): ?string
    {
        $meta = $crawler->filter(\sprintf('meta[property="og:%s"]', $property));
        self::assertCount(1, $meta);

        return $meta->attr('content');
    }
}
