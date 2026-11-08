<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Flux temps réel simulés, datés autour d'une heure fixée.
 */
trait RealtimeFeedMessageTrait
{
    private const string NOW = '2026-09-28 08:00:00';

    /**
     * @param FeedEntity[] $entities        identifiés par leur rang dans le flux
     * @param int|null     $headerTimestamp header.timestamp du flux ; null : l'heure fixée
     */
    private static function feedResponse(array $entities, ?int $headerTimestamp = null): MockResponse
    {
        return new MockResponse(self::serializedFeed($entities, $headerTimestamp));
    }

    /**
     * @param FeedEntity[] $entities        identifiés par leur rang dans le flux
     * @param int|null     $headerTimestamp header.timestamp du flux ; null : l'heure fixée
     */
    private static function serializedFeed(array $entities, ?int $headerTimestamp = null): string
    {
        foreach (array_values($entities) as $i => $entity) {
            $entity->setId((string) $i);
        }

        return new FeedMessage()
            ->setHeader(new FeedHeader()->setGtfsRealtimeVersion('2.0')->setTimestamp($headerTimestamp ?? self::at(0)))
            ->setEntity(array_values($entities))
            ->serializeToString();
    }

    /**
     * Timestamp à quelques minutes de l'heure fixée (négatif : dans le passé).
     */
    private static function at(int $minutes): int
    {
        return new \DateTimeImmutable(self::NOW)->getTimestamp() + $minutes * 60;
    }
}
