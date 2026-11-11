<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * The outlet key for a host, plus whether IDN conversion had to be skipped
 * because ext-intl is not loaded.
 */
final class HostKey
{
    public function __construct(
        public readonly string $key,
        public readonly bool $idnSkipped = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->key;
    }
}
