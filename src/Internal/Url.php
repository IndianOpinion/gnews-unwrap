<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Internal;

/**
 * Small URL toolkit: strict parsing, RFC 3986 reference resolution, dot
 * segment removal, raw query splitting and ASCII lowercasing.
 *
 * @internal
 */
final class Url
{
    public const MAX_LENGTH = 8192;

    /**
     * Parse an absolute URL. Returns null for anything that is not one.
     *
     * @return array{scheme: string, host: string, port: ?int, path: string, query: ?string, fragment: ?string}|null
     */
    public static function parse(string $url): ?array
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || self::hasControlOrSpace($url)) {
            return null;
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $url, $m) !== 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        return [
            'scheme' => self::lower($parts['scheme']),
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? null,
            'fragment' => $parts['fragment'] ?? null,
        ];
    }

    /** True when the string holds an ASCII control character or whitespace. */
    public static function hasControlOrSpace(string $value): bool
    {
        return preg_match('/[\x00-\x20\x7F]/', $value) === 1;
    }

    /** ASCII-only lowercasing, independent of the locale. */
    public static function lower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /** Lowercase and drop one trailing dot. */
    public static function normaliseHost(string $host): string
    {
        $host = self::lower($host);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        return $host;
    }

    /**
     * Resolve a reference (a Location header, an href) against a base URL,
     * per RFC 3986 section 5.2. Spaces are percent-encoded first. Returns
     * null when the result is not an absolute URL.
     */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = str_replace(' ', '%20', trim($reference));
        if ($reference === '' || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            return null;
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $reference) === 1) {
            $parts = self::split($reference);
            if ($parts === null) {
                return null;
            }
            $parts['path'] = self::removeDotSegments($parts['path']);

            return self::join($parts);
        }

        $baseParts = self::split($base);
        if ($baseParts === null || $baseParts['authority'] === null) {
            return null;
        }
        $refParts = self::split('x:' . $reference);
        if ($refParts === null) {
            return null;
        }
        $target = ['scheme' => $baseParts['scheme'], 'authority' => null, 'path' => '', 'query' => null, 'fragment' => $refParts['fragment']];

        if ($refParts['authority'] !== null) {
            $target['authority'] = $refParts['authority'];
            $target['path'] = self::removeDotSegments($refParts['path']);
            $target['query'] = $refParts['query'];
        } else {
            $target['authority'] = $baseParts['authority'];
            if ($refParts['path'] === '') {
                $target['path'] = $baseParts['path'];
                $target['query'] = $refParts['query'] ?? $baseParts['query'];
            } else {
                if (str_starts_with($refParts['path'], '/')) {
                    $target['path'] = self::removeDotSegments($refParts['path']);
                } else {
                    $basePath = $baseParts['path'] === '' ? '/' : $baseParts['path'];
                    $merged = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $refParts['path'];
                    $target['path'] = self::removeDotSegments($merged);
                }
                $target['query'] = $refParts['query'];
            }
        }

        $joined = self::join($target);

        return self::parse($joined) === null ? null : $joined;
    }

    /** RFC 3986 section 5.2.4. */
    public static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = substr($input, 2);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = substr($input, 3);
                $output = self::dropLastSegment($output);
            } elseif ($input === '/..') {
                $input = '/';
                $output = self::dropLastSegment($output);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $start = str_starts_with($input, '/') ? 1 : 0;
                $next = strpos($input, '/', $start);
                if ($next === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $next);
                    $input = substr($input, $next);
                }
            }
        }

        return $output;
    }

    /**
     * Split a raw query string into pairs without decoding or mangling
     * names (parse_str turns dots and spaces into underscores).
     *
     * @return list<array{0: string, 1: ?string}> name and value, value null when there was no '='
     */
    public static function queryPairs(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $piece) {
            if ($piece === '') {
                continue;
            }
            $eq = strpos($piece, '=');
            if ($eq === false) {
                $pairs[] = [$piece, null];
            } else {
                $pairs[] = [substr($piece, 0, $eq), substr($piece, $eq + 1)];
            }
        }

        return $pairs;
    }

    /**
     * First value of a query parameter, form-decoded once. Name match is
     * exact on the decoded name.
     */
    public static function queryParam(?string $query, string $name): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }
        foreach (self::queryPairs($query) as [$key, $value]) {
            if (urldecode($key) === $name && $value !== null) {
                return urldecode($value);
            }
        }

        return null;
    }

    private static function dropLastSegment(string $output): string
    {
        $pos = strrpos($output, '/');

        return $pos === false ? '' : substr($output, 0, $pos);
    }

    /**
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string}|null
     */
    private static function split(string $uri): ?array
    {
        // RFC 3986 appendix B.
        if (preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $uri, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return [
            'scheme' => $m[1] === null ? null : self::lower($m[1]),
            'authority' => $m[2],
            'path' => $m[3],
            'query' => $m[4],
            'fragment' => $m[5],
        ];
    }

    /**
     * @param array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string} $parts
     */
    private static function join(array $parts): string
    {
        $out = '';
        if ($parts['scheme'] !== null) {
            $out .= $parts['scheme'] . ':';
        }
        if ($parts['authority'] !== null) {
            $out .= '//' . $parts['authority'];
        }
        $out .= $parts['path'];
        if ($parts['query'] !== null) {
            $out .= '?' . $parts['query'];
        }
        if ($parts['fragment'] !== null) {
            $out .= '#' . $parts['fragment'];
        }

        return $out;
    }
}
