<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

/**
 * One HTTP request for one hop. Transports must not follow redirects.
 */
final class Request
{
    /**
     * @param array<string, string> $headers header name => value
     * @param float $connectTimeout seconds
     * @param float $timeout seconds for the whole request
     * @param int $maxBodyBytes read at most this many body bytes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $connectTimeout = 3.0,
        public readonly float $timeout = 8.0,
        public readonly int $maxBodyBytes = 524_288,
    ) {
    }
}
