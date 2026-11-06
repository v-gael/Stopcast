<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\PersonalDataMasker;
use App\Logging\SentryBeforeSend;
use App\Logging\SentryEventScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class SentryBeforeSendTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function clientErrorProvider(): iterable
    {
        yield '401 générique' => [new HttpException(401, 'Token invalide ou dispositif désactivé.')];
        yield '403' => [new AccessDeniedHttpException()];
        yield '429' => [new TooManyRequestsHttpException()];
    }

    #[DataProvider('clientErrorProvider')]
    public function testClientErrorIsNotSent(\Throwable $exception): void
    {
        self::assertNull($this->beforeSend()(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    /**
     * @return iterable<string, array{?\Throwable}>
     */
    public static function serverErrorProvider(): iterable
    {
        yield '503' => [new ServiceUnavailableHttpException()];
        yield 'exception hors HTTP' => [new \RuntimeException('Import failed')];
        yield 'log sans exception' => [null];
    }

    #[DataProvider('serverErrorProvider')]
    public function testOtherErrorsAreSentScrubbed(?\Throwable $exception): void
    {
        $event = Event::createEvent();
        $event->setMessage('Failed for jeanne@example.com');

        $sent = $this->beforeSend()($event, $exception instanceof \Throwable ? EventHint::fromArray(['exception' => $exception]) : null);

        self::assertSame('Failed for j***@e***.com', $sent?->getMessage());
    }

    private function beforeSend(): SentryBeforeSend
    {
        return new SentryBeforeSend(new SentryEventScrubber(new PersonalDataMasker()));
    }
}
