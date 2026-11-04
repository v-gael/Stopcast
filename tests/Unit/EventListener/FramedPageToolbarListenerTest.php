<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\FramedPageToolbarListener;
use App\EventListener\SameOriginFramingListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class FramedPageToolbarListenerTest extends TestCase
{
    public function testFramedPageLosesItsDebugTokenSoTheToolbarIsNotInjected(): void
    {
        $response = $this->dispatch([SameOriginFramingListener::ATTRIBUTE => true]);

        self::assertFalse($response->headers->has('X-Debug-Token'));
    }

    public function testPageWithoutToolbarLosesItsDebugToken(): void
    {
        $response = $this->dispatch([FramedPageToolbarListener::ATTRIBUTE => true]);

        self::assertFalse($response->headers->has('X-Debug-Token'));
    }

    public function testOtherPagesKeepTheirDebugToken(): void
    {
        $response = $this->dispatch([]);

        self::assertSame('abc123', $response->headers->get('X-Debug-Token'));
    }

    public function testSubRequestOfAFramedPageIsLeftAlone(): void
    {
        $response = $this->dispatch([SameOriginFramingListener::ATTRIBUTE => true], HttpKernelInterface::SUB_REQUEST);

        self::assertSame('abc123', $response->headers->get('X-Debug-Token'));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function dispatch(array $attributes, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = new Response('<html><body></body></html>', headers: ['X-Debug-Token' => 'abc123']);
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: $attributes), $requestType, $response);

        new FramedPageToolbarListener()($event);

        return $response;
    }
}
