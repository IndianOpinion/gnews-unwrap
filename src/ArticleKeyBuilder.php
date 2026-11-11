<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Builds a canonical article key from a publisher URL, so the same article
 * linked two ways compares equal. The key is for comparing and hashing,
 * not for display or fetching.
 *
 * Shape of a key: "<outlet key><path>[?<sorted query>]", with no scheme.
 */
final class ArticleKeyBuilder
{
    /**
     * Tracking parameters dropped by default. A trailing "*" matches a prefix.
     *
     * @var list<string>
     */
    public const DEFAULT_TRACKING_PARAMS = [
        'utm_*', 'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'mc_cid', 'mc_eid',
        'igshid', 'yclid', '_ga', '_gl', 'ref', 'ref_src', 'referrer', 'cmpid', 'ocid', 'oc',
        'ito', 's_cid', 'smid', 'src', 'source', 'at_medium', 'at_campaign', 'ns_mchannel',
        'ns_campaign', 'ncid', 'taid', 'ved', 'usg', 'ei',
    ];

    private const UNRESERVED = '/^[A-Za-z0-9\-._~]$/';

    private readonly HostNormaliser $hosts;
    /** @var array<string, true> */
    private readonly array $dropExact;
    /** @var list<string> */
    private readonly array $dropPrefixes;
    /** @var array<string, true>|null */
    private readonly ?array $allow;
    /** @var array<string, array<string, true>> outlet key => parameter names */
    private readonly array $identity;

    /**
     * @param list<string> $trackingParams names to drop, case-insensitive, "prefix*" allowed
     * @param list<string>|null $allowParams when set, keep only these names (plus identity ones)
     * @param bool $stripAmpMarkers drop /amp, /amp/, .amp, ?amp, ?amp=1 and outputType=amp (off by default)
     * @param array<string, list<string>> $identityParams outlet key => parameter names that identify the article
     */
    public function __construct(
        ?HostNormaliser $hosts = null,
        array $trackingParams = self::DEFAULT_TRACKING_PARAMS,
        ?array $allowParams = null,
        private readonly bool $stripAmpMarkers = false,
        array $identityParams = [],
    ) {
        $this->hosts = $hosts ?? new HostNormaliser();

        $exact = [];
        $prefixes = [];
        foreach ($trackingParams as $name) {
            $name = Url::lower(trim((string) $name));
            if ($name === '' || $name === '*') {
                throw new InvalidConfigException('Tracking parameter names must be non-empty and not "*".');
            }
            if (str_ends_with($name, '*')) {
                $prefixes[] = substr($name, 0, -1);
            } else {
                $exact[$name] = true;
            }
        }
        $this->dropExact = $exact;
        $this->dropPrefixes = $prefixes;

        if ($allowParams !== null) {
            $allow = [];
            foreach ($allowParams as $name) {
                $allow[Url::lower(trim((string) $name))] = true;
            }
            $this->allow = $allow;
        } else {
            $this->allow = null;
        }

        $identity = [];
        foreach ($identityParams as $host => $names) {
            $key = $this->hosts->key((string) $host);
            if ($key === '') {
                throw new InvalidConfigException('Identity-query host is not a valid host: ' . $host);
            }
            foreach ((array) $names as $name) {
                $identity[$key][Url::lower(trim((string) $name))] = true;
            }
        }
        $this->identity = $identity;
    }

    /** The article key, or '' for input that is not an absolute http(s) URL. */
    public function key(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $parts = Url::parse($url);
        if ($parts === null || ($parts['scheme'] !== 'http' && $parts['scheme'] !== 'https')) {
            return '';
        }
        $outlet = $this->hosts->key($parts['host']);
        if ($outlet === '') {
            return '';
        }
        $identity = $this->identity[$outlet] ?? [];

        $path = self::normalisePercent($parts['path']);
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = Url::removeDotSegments($path);
        if ($this->stripAmpMarkers) {
            $path = self::stripAmpPath($path);
        }
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        if ($path === '') {
            $path = '/';
        }

        $kept = [];
        foreach (Url::queryPairs($parts['query'] ?? '') as [$name, $value]) {
            $name = self::normalisePercent($name);
            $value = $value === null ? null : self::normalisePercent($value);
            $lower = Url::lower(urldecode($name));
            if (!isset($identity[$lower])) {
                if ($this->isTracking($lower)) {
                    continue;
                }
                if ($this->allow !== null && !isset($this->allow[$lower])) {
                    continue;
                }
                if ($this->stripAmpMarkers && self::isAmpParam($lower, $value)) {
                    continue;
                }
            }
            $kept[] = [$name, $value];
        }
        usort($kept, static function (array $a, array $b): int {
            return [$a[0], $a[1] ?? ''] <=> [$b[0], $b[1] ?? ''];
        });

        $query = implode('&', array_map(
            static fn (array $p): string => $p[1] === null ? $p[0] : $p[0] . '=' . $p[1],
            $kept
        ));

        return $outlet . $path . ($query === '' ? '' : '?' . $query);
    }

    private function isTracking(string $lowerName): bool
    {
        if (isset($this->dropExact[$lowerName])) {
            return true;
        }
        foreach ($this->dropPrefixes as $prefix) {
            if (str_starts_with($lowerName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isAmpParam(string $lowerName, ?string $value): bool
    {
        if ($lowerName === 'amp') {
            return true;
        }

        return $lowerName === 'outputtype' && $value !== null && Url::lower($value) === 'amp';
    }

    private static function stripAmpPath(string $path): string
    {
        // Leading /amp/ segment: /amp/news/story -> /news/story
        $path = (string) preg_replace('#^/amp(?=/)#', '', $path);
        // Trailing /amp or /amp/: /news/story/amp -> /news/story
        $path = (string) preg_replace('#/amp/?$#', '', $path);
        // .amp before an optional .html: story.amp.html -> story.html, story.amp -> story
        $path = (string) preg_replace('#\.amp(\.html?)?$#', '$1', $path);

        return $path === '' ? '/' : $path;
    }

    /**
     * Decode percent-encoded unreserved characters, upper-case the hex of
     * the rest, and encode a stray "%".
     */
    private static function normalisePercent(string $value): string
    {
        $value = (string) preg_replace('/%(?![0-9A-Fa-f]{2})/', '%25', $value);

        return (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $m): string {
                $char = chr((int) hexdec($m[1]));

                return preg_match(self::UNRESERVED, $char) === 1 ? $char : '%' . strtoupper($m[1]);
            },
            $value
        );
    }
}
