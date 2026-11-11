<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * One method the resolver tried, and why it failed (null when it worked).
 */
final class Attempt
{
    public function __construct(
        public readonly Method $method,
        public readonly ?Reason $reason,
    ) {
    }

    /** @return array{method: string, reason: ?string} */
    public function toArray(): array
    {
        return ['method' => $this->method->value, 'reason' => $this->reason?->value];
    }
}
