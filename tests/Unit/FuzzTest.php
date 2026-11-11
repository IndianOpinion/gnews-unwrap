<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\ArticleKeyBuilder;
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\HostNormaliser;
use IndianOpinion\GnewsUnwrap\Result;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

final class FuzzTest extends TestCase
{
    /** T68: 500 generated strings, no exception from any public entry point. */
    public function testNoExceptions(): void
    {
        mt_srand(20261111);
        $pieces = [
            'https://', 'http://', '//', 'news.google.com', 'news.url.google.com', 'www.google.com', '/rss/articles/',
            '/articles/', '/read/', '/__i/rss/rd/articles/', '/news/url?url=', '/url?q=', 'CBMi', 'AU_yqL', '%',
            '%2F', '%%', '?', '#', '&', '=', '.', '..', '/', ' ', "\0", "\xff", "\xC3", '[', ']', ':', '@',
            'example.com', 'xn--', 'bücher', 'javascript:', 'data:', '..%2F', '+', '-', '_', '~', '"', '<', '>',
        ];
        $corpus = [];
        for ($i = 0; $i < 500; $i++) {
            $s = '';
            $n = mt_rand(0, 12);
            for ($j = 0; $j < $n; $j++) {
                $s .= mt_rand(0, 4) === 0 ? chr(mt_rand(0, 255)) : $pieces[mt_rand(0, count($pieces) - 1)];
            }
            $corpus[] = $s;
        }

        $u = $this->unwrapper(new FakeTransport($this->clock), (new Config())->withWebRedirectorScope(true)->withGenericMode(true));
        $hosts = new HostNormaliser();
        $keys = new ArticleKeyBuilder();
        foreach ($corpus as $input) {
            $r = $u->resolve($input, 'https://news.google.com/');
            self::assertInstanceOf(Result::class, $r);
            self::assertSame($input, $r->input);
            if (!$r->resolved) {
                self::assertSame($input, $r->output);
            }
            $hosts->key($input);
            $keys->key($input);
            $u->detect($input);
        }
        self::assertCount(500, $corpus);
    }
}
