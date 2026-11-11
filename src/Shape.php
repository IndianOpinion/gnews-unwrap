<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * The Google wrapper link shapes the detector knows.
 *
 * S9 (locale-prefixed paths) from the PRD is not detected: no public
 * evidence was found that it exists. See docs/ARCHITECTURE.md.
 */
enum Shape: string
{
    /** news.google.com/rss/articles/<id> */
    case RssArticles = 'S1';
    /** news.google.com/articles/<id> */
    case Articles = 'S2';
    /** news.google.com/read/<id> */
    case Read = 'S3';
    /** news.google.com/__i/rss/rd/articles/<id> */
    case LegacyRssRedirect = 'S4';
    /** news.google.com/news/url?url=<publisher> */
    case NewsUrl = 'S5';
    /** news.url.google.com/url?url=<publisher> or q= */
    case NewsUrlHost = 'S6';
    /** www.google.<tld>/url?url=<publisher> or q= (only when scope is widened) */
    case WebRedirector = 'S7';
    /** ./articles/<id> or ./read/<id> against a Google News base URL */
    case Relative = 'S8';

    /** True when the link carries an opaque article id rather than a target parameter. */
    public function hasArticleId(): bool
    {
        return match ($this) {
            self::RssArticles, self::Articles, self::Read, self::LegacyRssRedirect, self::Relative => true,
            default => false,
        };
    }
}
