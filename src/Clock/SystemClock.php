<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
