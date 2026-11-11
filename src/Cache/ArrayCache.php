<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Cache;

use IndianOpinion\GnewsUnwrap\Clock\Clock;
use IndianOpinion\GnewsUnwrap\Clock\SystemClock;
use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;

/**
 * In-memory cache, bounded, least recently used out. Data dies with the
 * object. This is the default cache.
 */
final class ArrayCache implements Cache
{
    /** @var array<string, array{value: array<string, mixed>, expires: float}> */
    private array $items = [];

    private readonly Clock $clock;

    public function __construct(private readonly int $maxEntries = 10_000, ?Clock $clock = null)
    {
        if ($maxEntries < 1) {
            throw new InvalidConfigException('ArrayCache maxEntries must be at least 1.');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?array
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        $item = $this->items[$key];
        unset($this->items[$key]);
        if ($item['expires'] <= $this->clock->now()) {
            return null;
        }
        // Re-insert to mark as most recently used.
        $this->items[$key] = $item;

        return $item['value'];
    }

    public function set(string $key, array $value, int $ttl): void
    {
        unset($this->items[$key]);
        $this->items[$key] = ['value' => $value, 'expires' => $this->clock->now() + max(1, $ttl)];
        while (count($this->items) > $this->maxEntries) {
            $oldest = array_key_first($this->items);
            unset($this->items[$oldest]);
        }
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
