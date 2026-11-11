<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\ArticleKeyBuilder;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;

final class ArticleKeyTest extends TestCase
{
    /** T62: scheme, host variants, fragment, trailing slash, root. */
    public function testBasics(): void
    {
        $k = new ArticleKeyBuilder();
        $same = [
            'https://www.example.com/news/story-1/',
            'http://example.com/news/story-1',
            'https://m.example.com/news/story-1#comments',
            'https://amp.example.com/news/story-1',
            'https://EXAMPLE.com:443/news/story-1',
        ];
        foreach ($same as $url) {
            self::assertSame('example.com/news/story-1', $k->key($url), $url);
        }
        self::assertSame('example.com/', $k->key('https://www.example.com'));
        self::assertSame('example.com/', $k->key('https://www.example.com/'));
        self::assertSame('example.com/News/Story', $k->key('https://example.com/News/Story'), 'path case kept');
        self::assertSame('', $k->key('not a url'));
        self::assertSame('', $k->key('ftp://example.com/x'));
        self::assertSame('', $k->key(''));
    }

    /** T63: tracking parameters dropped case-insensitively, rest sorted, empty ? removed. */
    public function testTrackingParameters(): void
    {
        $k = new ArticleKeyBuilder();
        self::assertSame(
            'example.com/a?id=5&page=2',
            $k->key('https://example.com/a?utm_source=x&page=2&UTM_Medium=y&fbclid=abc&id=5&gclid=1&Ref=home')
        );
        self::assertSame('example.com/a', $k->key('https://example.com/a?utm_campaign=z&oc=5&ocid=msn&ved=2ah'));
        self::assertSame('example.com/a?a=1&a=2&b=1', $k->key('https://example.com/a?b=1&a=2&a=1'));
        self::assertSame('example.com/a', $k->key('https://example.com/a?'));
    }

    /** T64: valueless parameter, identity-query hosts, allow list. */
    public function testValuelessIdentityAndAllow(): void
    {
        $k = new ArticleKeyBuilder(stripAmpMarkers: false);
        self::assertSame('example.com/a?amp&x=1', $k->key('https://example.com/a?x=1&amp'));

        $identity = new ArticleKeyBuilder(identityParams: ['www.example.org' => ['src', 'id']]);
        self::assertSame('example.org/show.php?id=123&src=9', $identity->key('https://example.org/show.php?id=123&src=9&utm_source=x'));
        self::assertSame('example.net/show.php?id=123', $identity->key('https://example.net/show.php?id=123&src=9'));

        $allow = new ArticleKeyBuilder(allowParams: ['id']);
        self::assertSame('example.com/a?id=7', $allow->key('https://example.com/a?id=7&page=2&sort=new'));
    }

    /** T65: percent-encoding and dot segments. */
    public function testPercentAndDots(): void
    {
        $k = new ArticleKeyBuilder();
        self::assertSame('example.com/a/c', $k->key('https://example.com/a/b/../c'));
        self::assertSame('example.com/a/c', $k->key('https://example.com/a/./c'));
        self::assertSame('example.com/a/c', $k->key('https://example.com//a///c//'));
        self::assertSame('example.com/~user/a-b', $k->key('https://example.com/%7Euser/a%2Db'));
        self::assertSame('example.com/a%2Fb%C3%A9', $k->key('https://example.com/a%2fb%c3%a9'));
        self::assertSame('example.com/a/c', $k->key('https://example.com/a/b/%2E%2E/c'));
        self::assertSame('example.com/100%25', $k->key('https://example.com/100%'));
        self::assertSame('example.com/s?q=a%20b', $k->key('https://example.com/s?q=a%20b'));
    }

    /** T66, Q4: AMP markers kept by default (PRD 4.7.8), stripped when switched on. */
    public function testAmpMarkers(): void
    {
        $strip = new ArticleKeyBuilder(stripAmpMarkers: true);
        $keep = new ArticleKeyBuilder();
        $cases = [
            'https://example.com/news/story/amp' => ['example.com/news/story', 'example.com/news/story/amp'],
            'https://example.com/news/story/amp/' => ['example.com/news/story', 'example.com/news/story/amp'],
            'https://example.com/news/story.amp' => ['example.com/news/story', 'example.com/news/story.amp'],
            'https://example.com/news/story.amp.html' => ['example.com/news/story.html', 'example.com/news/story.amp.html'],
            'https://example.com/amp/news/story' => ['example.com/news/story', 'example.com/amp/news/story'],
            'https://example.com/news/story?amp=1' => ['example.com/news/story', 'example.com/news/story?amp=1'],
            'https://example.com/news/story?outputType=amp' => ['example.com/news/story', 'example.com/news/story?outputType=amp'],
            'https://example.com/amp' => ['example.com/', 'example.com/amp'],
            'https://example.com/news/amplify' => ['example.com/news/amplify', 'example.com/news/amplify'],
            'https://example.com/news/story?outputType=print' => ['example.com/news/story?outputType=print', 'example.com/news/story?outputType=print'],
        ];
        foreach ($cases as $url => [$stripped, $kept]) {
            self::assertSame($stripped, $strip->key($url), $url);
            self::assertSame($kept, $keep->key($url), $url);
        }
    }

    /** T62 (4.7.10): a wrapper keys on news.google.com. */
    public function testWrapperKey(): void
    {
        self::assertStringStartsWith('news.google.com/rss/articles/', (new ArticleKeyBuilder())->key(self::PAGE_A . '?oc=5'));
    }
}
