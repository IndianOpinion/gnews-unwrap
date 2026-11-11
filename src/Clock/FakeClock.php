<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Clock;

/**
 * A clock that only moves when told to. sleep() advances it.
 */
final class FakeClock implements Clock
{
    /** @var list<int> */
    private array $sleeps = [];

    public function __construct(private float $now = 1_700_000_000.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->advance($milliseconds / 1000);
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /** @return list<int> every sleep() call, in milliseconds */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
