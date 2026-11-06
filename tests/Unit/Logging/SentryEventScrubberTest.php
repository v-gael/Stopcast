<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\PersonalDataMasker;
use App\Logging\SentryEventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

final class SentryEventScrubberTest extends TestCase
{
    public function testPersonalDataIsMaskedEverywhereTheEventCarriesText(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Reset for %s', ['jeanne@example.com'], 'Reset for jeanne@example.com');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Duplicate key jeanne@example.com'))]);
        $event->setExtra(['monolog.context' => ['ip' => '203.0.113.42']]);
        $event->setContext('runtime', ['peer' => '2001:db8:1234:5678::1']);
        $event->setTags(['client' => '198.51.100.7']);
        $event->setUser(new UserDataBag('42', 'jeanne@example.com', '203.0.113.42'));
        $event->setRequest(['url' => 'https://stopcast.gaelv.com/reset-password/reset/Xk3vQ9pLmN2rT7wY', 'method' => 'GET']);

        $scrubbed = new SentryEventScrubber(new PersonalDataMasker())($event);

        self::assertSame('Reset for %s', $scrubbed->getMessage());
        self::assertSame(['j***@e***.com'], $scrubbed->getMessageParams());
        self::assertSame('Reset for j***@e***.com', $scrubbed->getMessageFormatted());
        self::assertSame('Duplicate key j***@e***.com', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame(['monolog.context' => ['ip' => '203.0.113.0']], $scrubbed->getExtra());
        self::assertSame(['peer' => '2001:db8:1234::'], $scrubbed->getContexts()['runtime']);
        self::assertSame(['client' => '198.51.100.0'], $scrubbed->getTags());
        self::assertNull($scrubbed->getUser());
        self::assertSame(['url' => 'https://stopcast.gaelv.com/reset-password/reset/***', 'method' => 'GET'], $scrubbed->getRequest());
    }
}
