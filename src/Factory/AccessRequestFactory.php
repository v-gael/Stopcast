<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\AccessRequest;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AccessRequest>
 */
final class AccessRequestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AccessRequest::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'message' => null,
            'requestedAt' => new \DateTimeImmutable(),
        ];
    }
}
