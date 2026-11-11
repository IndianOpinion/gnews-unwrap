<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * What the detector found in a URL. Pure data, no network involved.
 */
final class Detection
{
    /**
     * @param string $input the string as given
     * @param bool $isWrapper true when the URL is a Google wrapper this library resolves
     * @param Shape|null $shape set for wrappers (and for a wrapper with a malformed id)
     * @param string|null $articleId the id segment, for S1 to S4 and S8
     * @param string|null $target the url or q parameter, decoded once, for S5 to S7
     * @param Reason|null $reason why it is not a wrapper (null for wrappers)
     * @param string|null $url the absolute URL that was examined (after base resolution)
     * @param string|null $normalised canonical wrapper form, used as cache key and request URL
     */
    public function __construct(
        public readonly string $input,
        public readonly bool $isWrapper,
        public readonly ?Shape $shape = null,
        public readonly ?string $articleId = null,
        public readonly ?string $target = null,
        public readonly ?Reason $reason = null,
        public readonly ?string $url = null,
        public readonly ?string $normalised = null,
    ) {
    }

    public static function no(string $input, Reason $reason, ?string $url = null, ?Shape $shape = null): self
    {
        return new self($input, false, $shape, null, null, $reason, $url);
    }
}
