<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FlashComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersEachSupportedFlashType(): void
    {
        $session = $this->pushRequestWithSession();
        $session->getFlashBag()->add('success', 'Dispositif enregistré.');
        $session->getFlashBag()->add('error', 'Action impossible.');
        $session->getFlashBag()->add('warning', 'Invitation expirée.');
        $session->getFlashBag()->add('info', 'Type non géré.');

        $crawler = $this->renderTwigComponent('Flash')->crawler();

        self::assertSame('Dispositif enregistré.', $crawler->filter('[role="status"]')->text());
        self::assertSame(['Action impossible.', 'Invitation expirée.'], $crawler->filter('[role="alert"]')->each(static fn (Crawler $alert): string => $alert->text()));
        self::assertStringNotContainsString('Type non géré.', $crawler->html());
    }

    public function testRendersNothingWithoutMessages(): void
    {
        $this->pushRequestWithSession();

        self::assertSame('', trim($this->renderTwigComponent('Flash')->toString()));
    }

    public function testDoesNotStartASessionForVisitorsWithoutOne(): void
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $this->pushRequest($request);

        self::assertSame('', trim($this->renderTwigComponent('Flash')->toString()));
        self::assertFalse($session->isStarted());
    }

    private function pushRequestWithSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'session-existante');
        $this->pushRequest($request);

        return $session;
    }

    private function pushRequest(Request $request): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);
    }
}
