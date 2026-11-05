<?php

declare(strict_types=1);

namespace App\Gtfs\Static;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * ETag et Last-Modified d'un fichier de flux : renvoyés au serveur, ils lui
 * permettent de répondre 304 quand le fichier n'a pas changé.
 */
final readonly class FeedValidators
{
    public function __construct(
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $headers = $response->getHeaders(false);

        return new self($headers['etag'][0] ?? null, $headers['last-modified'][0] ?? null);
    }

    public function isEmpty(): bool
    {
        return null === $this->etag && null === $this->lastModified;
    }

    /**
     * @return array<string, string>
     */
    public function toConditionalHeaders(): array
    {
        $headers = [];
        if (null !== $this->etag) {
            $headers['If-None-Match'] = $this->etag;
        }

        if (null !== $this->lastModified) {
            $headers['If-Modified-Since'] = $this->lastModified;
        }

        return $headers;
    }
}
