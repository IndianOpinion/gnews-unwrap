<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Clock;

/**
 * Time source used for budgets, cache lifetimes, the circuit breaker and
 * the pause between network resolutions. Swap it for FakeClock in tests.
 */
interface Clock
{
    /** Seconds as a float. Only differences matter, except for cache expiry. */
    public function now(): float;

    /** Pause for the given number of milliseconds. */
    public function sleep(int $milliseconds): void;
}
