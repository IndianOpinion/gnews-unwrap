<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Says whether a URL is a Google News wrapper, which shape, and what it
 * carries. Pure: no network, no cache, never throws for bad input.
 */
final class Detector
{
    /** Article ids: base64 (both alphabets), optional padding. */
    private const ID_PATTERN = '/^[A-Za-z0-9_\-+]+={0,2}$/';
    private const MAX_ID_LENGTH = 4096;

    /** @var array<string, Shape> path prefix => shape, for news.google.com */
    private const ID_PATHS = [
        '/rss/articles/' => Shape::RssArticles,
        '/articles/' => Shape::Articles,
        '/read/' => Shape::Read,
        '/__i/rss/rd/articles/' => Shape::LegacyRssRedirect,
    ];

    /**
     * @param bool $webRedirectorScope treat www.google.<tld>/url?url= (S7) as a wrapper
     */
    public function __construct(private readonly bool $webRedirectorScope = false)
    {
    }

    public function withWebRedirectorScope(bool $on): self
    {
        return new self($on);
    }

    /**
     * @param string|null $baseUrl base for relative links (S8); must be on news.google.com
     */
    public function detect(string $input, ?string $baseUrl = null): Detection
    {
        if (strlen($input) > Url::MAX_LENGTH) {
            return Detection::no($input, Reason::InvalidUrl);
        }
        $url = trim($input, " \t\n\r\0\x0B");
        if ($url === '' || Url::hasControlOrSpace($url) || preg_match('//u', $url) !== 1) {
            return Detection::no($input, Reason::InvalidUrl);
        }

        $relative = false;
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $url) !== 1) {
            // No scheme. Only relative Google News links with a Google News base are accepted.
            if ($baseUrl === null || str_starts_with($url, '//')) {
                return Detection::no($input, Reason::InvalidUrl);
            }
            $base = Url::parse(trim($baseUrl));
            if ($base === null || Url::normaliseHost($base['host']) !== GoogleHosts::NEWS_HOST) {
                return Detection::no($input, Reason::InvalidUrl);
            }
            $resolved = Url::resolve(trim($baseUrl), $url);
            if ($resolved === null) {
                return Detection::no($input, Reason::InvalidUrl);
            }
            $url = $resolved;
            $relative = true;
        }

        $parts = Url::parse($url);
        if ($parts === null || ($parts['scheme'] !== 'http' && $parts['scheme'] !== 'https')) {
            return Detection::no($input, Reason::InvalidUrl);
        }
        $host = Url::normaliseHost($parts['host']);
        if ($host === '') {
            return Detection::no($input, Reason::InvalidUrl);
        }

        if ($host === GoogleHosts::NEWS_HOST) {
            return $this->detectNewsHost($input, $url, $parts['path'], $parts['query'], $relative);
        }

        if ($host === GoogleHosts::NEWS_URL_HOST) {
            if ($parts['path'] === '/url' || $parts['path'] === '/url/') {
                return $this->targetDetection($input, $url, Shape::NewsUrlHost, $host, $parts['path'], $parts['query']);
            }

            return Detection::no($input, Reason::NotAnArticleLink, $url);
        }

        if (GoogleHosts::isWebRedirectorHost($host) && ($parts['path'] === '/url' || $parts['path'] === '/url/')) {
            if (!$this->webRedirectorScope) {
                return Detection::no($input, Reason::NotGoogleNews, $url);
            }

            return $this->targetDetection($input, $url, Shape::WebRedirector, $host, $parts['path'], $parts['query']);
        }

        return Detection::no($input, Reason::NotGoogleNews, $url);
    }

    private function detectNewsHost(string $input, string $url, string $path, ?string $query, bool $relative): Detection
    {
        foreach (self::ID_PATHS as $prefix => $shape) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            if (str_ends_with($rest, '/')) {
                $rest = substr($rest, 0, -1);
            }
            if ($rest === '' || str_contains($rest, '/')) {
                return Detection::no($input, Reason::NotAnArticleLink, $url);
            }
            if ($relative) {
                $shape = ($shape === Shape::Articles || $shape === Shape::Read) ? Shape::Relative : null;
                if ($shape === null) {
                    return Detection::no($input, Reason::NotAnArticleLink, $url);
                }
            }
            $id = rawurldecode($rest);
            if (strlen($id) > self::MAX_ID_LENGTH || preg_match(self::ID_PATTERN, $id) !== 1) {
                return Detection::no($input, Reason::MalformedId, $url, $shape);
            }
            $normalised = 'https://' . GoogleHosts::NEWS_HOST . $prefix . $id;

            return new Detection($input, true, $shape, $id, null, null, $url, $normalised);
        }

        if ($path === '/news/url' || $path === '/news/url/') {
            return $this->targetDetection($input, $url, Shape::NewsUrl, GoogleHosts::NEWS_HOST, $path, $query);
        }

        return Detection::no($input, Reason::NotAnArticleLink, $url);
    }

    private function targetDetection(string $input, string $url, Shape $shape, string $host, string $path, ?string $query): Detection
    {
        $target = Url::queryParam($query, 'url');
        if ($target === null || trim($target) === '') {
            $target = Url::queryParam($query, 'q');
        }
        if ($target === null || trim($target) === '') {
            return Detection::no($input, Reason::NotAnArticleLink, $url);
        }
        $normalised = 'https://' . $host . $path . ($query === null || $query === '' ? '' : '?' . $query);

        return new Detection($input, true, $shape, null, $target, null, $url, $normalised);
    }
}
