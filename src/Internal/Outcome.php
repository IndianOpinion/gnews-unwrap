<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Internal;

use IndianOpinion\GnewsUnwrap\Method;

/**
 * Result of the method chain for one link, before it becomes a Result.
 *
 * @internal
 */
final class Outcome
{
    public function __construct(
        public readonly ?string $target,
        public readonly ?Method $method,
    ) {
    }
}
