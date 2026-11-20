<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Deux configurations de PHPUnit : la suite sous DAMA, sans les tests
 * navigateur, et les tests navigateur sans DAMA. Elles ne diffèrent que par
 * là : un réglage ajouté à l'une est ajouté à l'autre.
 */
final class PhpunitConfigurationTest extends TestCase
{
    private const string DAMA_EXTENSION = \DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension::class;

    public function testTheMainConfigurationRunsUnderDamaWithoutTheBrowserTests(): void
    {
        $main = $this->load('phpunit.dist.xml');

        self::assertSame(['tests'], $this->values($main, '/phpunit/testsuites/testsuite/directory'));
        self::assertSame(['browser'], $this->values($main, '/phpunit/groups/exclude/group'));
        self::assertContains(self::DAMA_EXTENSION, $this->values($main, '/phpunit/extensions/bootstrap/@class'));
    }

    public function testTheBrowserConfigurationRunsTheBrowserTestsWithoutDama(): void
    {
        $browser = $this->load('phpunit.browser.dist.xml');

        self::assertSame(['tests/Browser'], $this->values($browser, '/phpunit/testsuites/testsuite/directory'));
        self::assertSame([], $this->values($browser, '/phpunit/groups'));
        self::assertNotContains(self::DAMA_EXTENSION, $this->values($browser, '/phpunit/extensions/bootstrap/@class'));
    }

    public function testBothConfigurationsShareEverythingElse(): void
    {
        self::assertSame(
            $this->withoutTheirDifferences($this->load('phpunit.dist.xml')),
            $this->withoutTheirDifferences($this->load('phpunit.browser.dist.xml')),
        );
    }

    private function load(string $file): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        self::assertTrue($document->load(\dirname(__DIR__, 2).'/'.$file));

        return $document;
    }

    /**
     * @return list<string>
     */
    private function values(\DOMDocument $document, string $query): array
    {
        $nodes = new \DOMXPath($document)->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        $values = [];
        foreach ($nodes as $node) {
            $values[] = trim((string) $node->nodeValue);
        }

        return $values;
    }

    private function withoutTheirDifferences(\DOMDocument $document): string
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(\sprintf(
            '//comment() | /phpunit/testsuites | /phpunit/groups[count(*) = 1 and exclude[count(*) = 1 and group = "browser"]] | /phpunit/extensions/bootstrap[@class="%s"]',
            self::DAMA_EXTENSION,
        ));
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        foreach (iterator_to_array($nodes) as $node) {
            if ($node instanceof \DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $document->C14N();
    }
}
