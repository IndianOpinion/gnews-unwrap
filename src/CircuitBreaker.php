<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Clock\Clock;

/**
 * Opens after N rate_limited results inside a window, and stays open for a
 * cooldown. While open, network methods are skipped and offline methods
 * still run. A breaker beats a retry loop: Google answers bulk retries with
 * more 429s.
 */
final class CircuitBreaker
{
    /** @var list<float> */
    private array $hits = [];
    private float $openUntil = 0.0;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $threshold = 3,
        private readonly int $window = 60,
        private readonly int $cooldown = 300,
    ) {
    }

    public function recordRateLimit(): void
    {
        $now = $this->clock->now();
        $this->hits[] = $now;
        $this->hits = array_values(array_filter(
            $this->hits,
            fn (float $t): bool => $t > $now - $this->window
        ));
        if (count($this->hits) >= $this->threshold) {
            $this->openUntil = $now + $this->cooldown;
            $this->hits = [];
        }
    }

    public function isOpen(): bool
    {
        return $this->clock->now() < $this->openUntil;
    }

    /** Seconds until the breaker closes, 0 when closed. */
    public function secondsUntilClose(): float
    {
        return max(0.0, $this->openUntil - $this->clock->now());
    }

    public function reset(): void
    {
        $this->hits = [];
        $this->openUntil = 0.0;
    }
}
