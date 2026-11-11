<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Log;

/**
 * Adapts any callable taking (level, message, context) to Logger.
 */
final class CallableLogger implements Logger
{
    /** @var \Closure(string, string, array<string, mixed>): mixed */
    private readonly \Closure $callback;

    /** @param callable(string, string, array<string, mixed>): mixed $callback */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        ($this->callback)($level, $message, $context);
    }
}
