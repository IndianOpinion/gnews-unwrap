<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Decides whether a candidate publisher URL may be returned as resolved.
 * When in doubt it says no: a wrong publisher URL is worse than an
 * unresolved one.
 */
final class TargetValidator
{
    public const MAX_LENGTH = 2048;

    /** @var list<string> outlet keys and lowercased hosts of the caller's own sites */
    private readonly array $ownHosts;

    /**
     * @param list<string> $ownHosts hosts (or URLs) of the caller's own sites; never valid targets
     */
    public function __construct(
        private readonly GoogleHosts $google = new GoogleHosts(),
        private readonly HostNormaliser $hosts = new HostNormaliser(),
        array $ownHosts = [],
        private readonly bool $allowPrivate = false,
    ) {
        $own = [];
        foreach ($ownHosts as $host) {
            $key = $this->hosts->key((string) $host);
            if ($key !== '') {
                $own[] = $key;
            }
        }
        $this->ownHosts = array_values(array_unique($own));
    }

    /**
     * Returns the cleaned target, or null when it must be rejected.
     *
     * @param string|null $wrapper the normalised wrapper, which is never its own target
     */
    public function accept(string $candidate, ?string $wrapper = null): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH || Url::hasControlOrSpace($candidate)) {
            return null;
        }
        if (preg_match('//u', $candidate) !== 1) {
            return null;
        }
        $parts = Url::parse($candidate);
        if ($parts === null || ($parts['scheme'] !== 'http' && $parts['scheme'] !== 'https')) {
            return null;
        }
        $host = Url::normaliseHost($parts['host']);
        if ($host === '' || $this->google->isGoogle($host)) {
            return null;
        }
        if (!str_starts_with($host, '[') && preg_match('/^[\p{L}\p{M}\p{N}._-]+$/u', $host) !== 1) {
            return null;
        }
        if (!$this->allowPrivate && (AddressGuard::isIpLike($host) || AddressGuard::isLocalName($host))) {
            return null;
        }
        if ($this->ownHosts !== []) {
            $key = $this->hosts->key($host);
            foreach ($this->ownHosts as $own) {
                if ($key === $own || str_ends_with($host, '.' . $own)) {
                    return null;
                }
            }
        }
        if ($wrapper !== null && self::sameUrl($candidate, $wrapper)) {
            return null;
        }

        return $candidate;
    }

    private static function sameUrl(string $a, string $b): bool
    {
        $strip = static function (string $url): string {
            $url = (string) preg_replace('/#.*$/s', '', $url);
            $url = (string) preg_replace('#^https?://#i', '', $url);

            return rtrim(Url::lower($url), '/');
        };

        return $strip($a) === $strip($b);
    }
}
