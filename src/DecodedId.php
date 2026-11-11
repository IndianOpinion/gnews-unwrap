<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * What LegacyIdDecoder found: the publisher URL and, on some ids, an AMP
 * URL; or the reason it found nothing.
 */
final class DecodedId
{
    public function __construct(
        public readonly ?string $url,
        public readonly ?string $ampUrl,
        public readonly ?Reason $reason,
    ) {
    }

    public static function failed(Reason $reason): self
    {
        return new self(null, null, $reason);
    }
}
