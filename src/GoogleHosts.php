<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Knows which hosts belong to Google for the purpose of this library: a
 * target on one of them is never accepted, and redirects between them are
 * followed.
 *
 * Built in: google.<tld> and every subdomain of it (news.google.com,
 * news.url.google.com, consent.google.com, accounts.google.com, ...),
 * gstatic.com and its subdomains, and consent.youtube.com. YouTube itself
 * is not on the list: a wrapper that points at a video is a valid result.
 */
final class GoogleHosts
{
    public const NEWS_HOST = 'news.google.com';
    public const NEWS_URL_HOST = 'news.url.google.com';

    /** google.com, google.co.in, google.com.au, google.de and so on. */
    private const GOOGLE_DOMAIN = '(?:[a-z0-9-]+\.)*google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})';

    /** @var list<string> */
    private readonly array $extra;

    /**
     * @param list<string> $extraHosts more hosts to treat as Google (subdomains included)
     */
    public function __construct(array $extraHosts = [])
    {
        $this->extra = array_map(
            static fn (string $h): string => Url::normaliseHost(trim($h)),
            $extraHosts
        );
    }

    public function isGoogle(string $host): bool
    {
        $host = Url::normaliseHost($host);
        if ($host === '') {
            return false;
        }
        if (preg_match('/^' . self::GOOGLE_DOMAIN . '$/', $host) === 1) {
            return true;
        }
        if ($host === 'gstatic.com' || str_ends_with($host, '.gstatic.com') || $host === 'consent.youtube.com') {
            return true;
        }
        foreach ($this->extra as $extra) {
            if ($extra !== '' && ($host === $extra || str_ends_with($host, '.' . $extra))) {
                return true;
            }
        }

        return false;
    }

    /** google.<tld> or www.google.<tld>: the web redirector hosts (shape S7). */
    public static function isWebRedirectorHost(string $host): bool
    {
        return preg_match('/^(?:www\.)?google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/', Url::normaliseHost($host)) === 1;
    }

    public static function isConsentHost(string $host): bool
    {
        $host = Url::normaliseHost($host);

        return $host === 'consent.youtube.com'
            || preg_match('/^consent\.google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/', $host) === 1;
    }

    public static function isLoginHost(string $host): bool
    {
        return preg_match('/^accounts\.google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/', Url::normaliseHost($host)) === 1;
    }

    /** True for the google.<tld>/sorry/... rate-limit page. */
    public static function isSorryPage(string $url): bool
    {
        $parts = Url::parse($url);
        if ($parts === null) {
            return false;
        }

        return self::isWebRedirectorHost($parts['host'])
            && (str_starts_with($parts['path'], '/sorry/') || $parts['path'] === '/sorry');
    }
}
