<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Log;

/**
 * PSR-3-shaped logging hook with no dependency on psr/log. The resolver
 * writes one debug line per resolution and one warning for rate_limited,
 * format_changed and circuit_open.
 *
 * To use a PSR-3 logger: new CallableLogger(fn ($level, $message, $context) => $psr->log($level, $message, $context)).
 */
interface Logger
{
    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void;
}
