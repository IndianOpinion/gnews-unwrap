<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

final class QueryParamTest extends TestCase
{
    /** T15: url and q parameters, percent-encoded, Google params leaked into the value. */
    public function testReadsUrlAndQ(): void
    {
        $transport = new FakeTransport($this->clock);
        $u = $this->unwrapper($transport, (new Config())->withWebRedirectorScope(true));

        $cases = [
            'https://news.google.com/news/url?sa=t&fd=R&url=https%3A%2F%2Fexample.com%2Fstory%2F1' => 'https://example.com/story/1',
            'http://news.url.google.com/url?q=https://example.com/story/2&ct=ga' => 'https://example.com/story/2',
            'https://www.google.com/url?rct=j&sa=t&url=https://example.com/alert/story&ct=ga&usg=AOvVaw0' => 'https://example.com/alert/story',
            // Leaked: the value itself carries Google's own trailing parameters.
            'https://www.google.com/url?url=https%3A%2F%2Fexample.com%2Fa%3Fid%3D9%26ct%3Dga%26usg%3DAOv%26ved%3D2ah' => 'https://example.com/a?id=9',
            // url wins over q.
            'https://news.url.google.com/url?q=https%3A%2F%2Fexample.org%2Fq&url=https%3A%2F%2Fexample.org%2Furl' => 'https://example.org/url',
        ];
        foreach ($cases as $wrapper => $expected) {
            $r = $u->resolve($wrapper);
            self::assertTrue($r->resolved, $wrapper);
            self::assertSame($expected, $r->output);
            self::assertSame(Method::QueryParam, $r->method);
        }
        self::assertSame([], $transport->requests(), 'query_param never makes a request');
    }

    /** T16: nested 2 levels resolve; 4 levels stop after 3. */
    public function testNesting(): void
    {
        $publisher = 'https://example.com/deep/story';
        $level1 = 'https://news.url.google.com/url?q=' . rawurlencode($publisher);
        $level2 = 'https://www.google.com/url?url=' . rawurlencode($level1);
        $level3 = 'https://news.google.com/news/url?url=' . rawurlencode($level2);
        $level4 = 'https://www.google.com/url?q=' . rawurlencode($level3);

        $transport = new FakeTransport($this->clock);
        $u = $this->unwrapper($transport, (new Config())->withWebRedirectorScope(true)->withoutMethods(Method::HttpRedirect));

        $two = $u->resolve($level2);
        self::assertTrue($two->resolved);
        self::assertSame($publisher, $two->output);

        $three = $u->resolve($level3);
        self::assertTrue($three->resolved);

        $four = $u->resolve($level4);
        self::assertFalse($four->resolved);
        self::assertSame(Reason::BadTarget, $four->reason);
        self::assertSame($level4, $four->output);
    }

    /** 4.10: a redirector whose url is a Google News article wrapper is unwrapped level by level. */
    public function testRedirectorAroundArticleWrapper(): void
    {
        $id = \IndianOpinion\GnewsUnwrap\LegacyIdDecoder::encode('https://example.com/inner/legacy');
        $wrapper = 'https://news.google.com/rss/articles/' . $id . '?oc=5';
        $outer = 'https://news.url.google.com/url?url=' . rawurlencode($wrapper);

        $u = $this->unwrapper(new FakeTransport($this->clock));
        $r = $u->resolve($outer);
        self::assertTrue($r->resolved);
        self::assertSame('https://example.com/inner/legacy', $r->output);
        self::assertSame(Method::OfflineDecode, $r->method);
        self::assertSame([Method::QueryParam, Method::OfflineDecode], array_map(static fn ($a) => $a->method, $r->attempts));
    }

    public function testRejectsTargetBackOnGoogle(): void
    {
        $u = $this->unwrapper(new FakeTransport($this->clock), (new Config())->withoutMethods(Method::HttpRedirect));
        $r = $u->resolve('https://news.google.com/news/url?url=' . rawurlencode('https://news.google.com/topics/abc'));
        self::assertFalse($r->resolved);
        self::assertSame(Reason::BadTarget, $r->reason);
    }
}
