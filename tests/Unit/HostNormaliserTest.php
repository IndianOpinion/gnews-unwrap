<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\HostNormaliser;
use IndianOpinion\GnewsUnwrap\PublicSuffixList;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostNormaliserTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        // T54: extraction, lowercase, port, userinfo, trailing dot.
        yield 'url' => ['https://Example.COM/path?q=1#f', 'example.com'];
        yield 'bare host' => ['Example.com', 'example.com'];
        yield 'port' => ['https://example.com:8080/a', 'example.com'];
        yield 'bare host with port' => ['example.com:8443', 'example.com'];
        yield 'userinfo' => ['https://user:pass@www.example.com/a', 'example.com'];
        yield 'trailing dot' => ['https://example.com./a', 'example.com'];
        yield 'protocol relative' => ['//www.example.com/x', 'example.com'];
        yield 'bare host with path' => ['www.example.com/news/1', 'example.com'];
        // T55: IP literals untouched.
        yield 'ipv4' => ['http://93.184.215.14:8080/x', '93.184.215.14'];
        yield 'ipv6' => ['http://[2001:DB8::1]:8080/x', '[2001:db8::1]'];
        yield 'bare ipv6' => ['2001:DB8::1', '2001:db8::1'];
        // T56: strip list, repeatedly; numbered www.
        yield 'www' => ['www.example.com', 'example.com'];
        yield 'amp' => ['amp.example.com', 'example.com'];
        yield 'm' => ['m.example.com', 'example.com'];
        yield 'mobile' => ['mobile.example.com', 'example.com'];
        yield 'm.amp' => ['m.amp.example.com', 'example.com'];
        yield 'www.m' => ['www.m.example.com', 'example.com'];
        yield 'amp.www' => ['amp.www.example.com', 'example.com'];
        yield 'www2' => ['www2.example.com', 'example.com'];
        yield 'ww2' => ['ww2.example.com', 'example.com'];
        yield 'www10' => ['www10.example.com', 'example.com'];
        // T57: floor at the registrable domain.
        yield 'm.com' => ['m.com', 'm.com'];
        yield 'www.com' => ['www.com', 'www.com'];
        yield 'www.co.uk' => ['www.co.uk', 'www.co.uk'];
        yield 'amp.example.co.uk' => ['amp.example.co.uk', 'example.co.uk'];
        yield 'www.m.example.co.in' => ['www.m.example.co.in', 'example.co.in'];
        yield 'm.example.com.au' => ['https://m.example.com.au/', 'example.com.au'];
        // T59: lookalike labels and middle labels kept.
        yield 'mobile-news' => ['mobile-news.example.com', 'mobile-news.example.com'];
        yield 'amplify' => ['amplify.example.com', 'amplify.example.com'];
        yield 'mtv' => ['mtv.com', 'mtv.com'];
        yield 'wwwx' => ['wwwx.example.com', 'wwwx.example.com'];
        yield 'middle m' => ['news.m.example.com', 'news.m.example.com'];
        yield 'subdomain kept' => ['www.business.example.com', 'business.example.com'];
        // T61: a wrapper gives news.google.com.
        yield 'wrapper' => ['https://news.google.com/rss/articles/CBMiabc?oc=5', 'news.google.com'];
        // Junk.
        yield 'empty' => ['', ''];
        yield 'space' => ['exa mple.com', ''];
        yield 'bad chars' => ['exa<mple.com', ''];
        yield 'empty label' => ['example..com', ''];
        yield 'no host url' => ['https:///a', ''];
    }

    #[DataProvider('cases')]
    public function testKey(string $input, string $expected): void
    {
        self::assertSame($expected, (new HostNormaliser())->key($input));
    }

    /** T58: Unicode and punycode give one key with intl; without it, flagged. */
    public function testIdn(): void
    {
        if (function_exists('idn_to_ascii')) {
            $n = new HostNormaliser();
            $unicode = $n->normalise('https://www.bücher.example/a');
            $puny = $n->normalise('https://XN--BCHER-KVA.example/a');
            self::assertSame('xn--bcher-kva.example', $unicode->key);
            self::assertSame($unicode->key, $puny->key);
            self::assertFalse($unicode->idnSkipped);
            self::assertSame('xn--h2brj9c.xn--h2brj9c', $n->key('भारत.भारत'));
            self::assertSame($n->key('भारत.भारत'), $n->key('XN--H2BRJ9C.xn--h2brj9c'));
        }

        $plain = new HostNormaliser(useIntl: false);
        $key = $plain->normalise('https://www.BÜCHER.example/a');
        self::assertTrue($key->idnSkipped);
        self::assertSame(function_exists('mb_strtolower') ? 'bücher.example' : 'bÜcher.example', $key->key);
        self::assertFalse($plain->normalise('https://xn--bcher-kva.example/')->idnSkipped);
    }

    /** T60: caller aliases run last. */
    public function testAliases(): void
    {
        $n = new HostNormaliser(aliases: [
            'business.example.com' => 'example.com',
            '*.example.org' => 'example.org',
        ]);
        self::assertSame('example.com', $n->key('https://www.business.example.com/x'));
        self::assertSame('example.org', $n->key('https://sport.example.org/x'));
        self::assertSame('example.org', $n->key('https://example.org/x'));
        self::assertSame('news.example.net', $n->key('https://news.example.net/x'));
    }

    public function testCustomStripAndSuffixes(): void
    {
        $n = new HostNormaliser(['www', 'en'], false, ['example.test']);
        self::assertSame('news.example.test', $n->key('en.www.news.example.test'));
        self::assertSame('m.example.com', $n->key('m.example.com'));
        self::assertSame('www2.example.com', $n->key('www2.example.com'));
    }

    /** Q3: a Public Suffix List file can be loaded from disk. */
    public function testPublicSuffixList(): void
    {
        $psl = PublicSuffixList::fromString(<<<'PSL'
            // comment
            com
            uk
            co.uk
            *.ck
            !www.ck
            blogspot.com
            PSL);
        $n = new HostNormaliser(publicSuffixList: $psl);
        self::assertSame('example.co.uk', $n->key('www.example.co.uk'));
        self::assertSame('example.blogspot.com', $n->key('www.example.blogspot.com'));
        self::assertSame('example.foo.ck', $n->key('www.example.foo.ck'));
        self::assertSame('www.foo.ck', $n->key('www.foo.ck'));
        self::assertSame('www.ck', $n->key('www.ck'), 'exception rule: www.ck is registrable');
        self::assertSame(1, $psl->suffixLabelCount(['example', 'unknowntld']));

        $file = tempnam(sys_get_temp_dir(), 'psl');
        self::assertIsString($file);
        file_put_contents($file, "com\nco.in\n");
        try {
            self::assertSame(2, PublicSuffixList::fromFile($file)->suffixLabelCount(['m', 'example', 'co', 'in']));
        } finally {
            unlink($file);
        }
    }
}
