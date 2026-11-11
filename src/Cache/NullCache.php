<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Cache;

/**
 * Stores nothing. Use it when the caller keeps its own store of resolved
 * URLs and wants every call to do the work.
 */
final class NullCache implements Cache
{
    public function get(string $key): ?array
    {
        return null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
    }

    public function delete(string $key): void
    {
    }
}
