<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Cache;

/**
 * Minimal cache contract. Values are plain arrays (a Result::toArray()), so
 * any backend that stores JSON or serialised arrays can implement it.
 *
 * Implementations must not throw for a missing key; return null.
 */
interface Cache
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     * @param int $ttl lifetime in seconds, always greater than zero
     */
    public function set(string $key, array $value, int $ttl): void;

    public function delete(string $key): void;
}
