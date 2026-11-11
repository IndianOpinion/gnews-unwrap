<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Detector;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Shape;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DetectorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function wrapperShapes(): iterable
    {
        foreach (self::urlList() as $group => $urls) {
            if (!str_starts_with($group, 'S') || $group === 'S7') {
                continue;
            }
            foreach ($urls as $i => $url) {
                yield $group . ' #' . $i => [$url, $group];
            }
        }
    }

    /** T01 to T06: every known shape is detected from the URL alone. */
    #[DataProvider('wrapperShapes')]
    public function testDetectsShape(string $url, string $shape): void
    {
        $d = (new Detector())->detect($url);
        self::assertTrue($d->isWrapper, $url);
        self::assertSame($shape, $d->shape?->value);
        self::assertNull($d->reason);
    }

    /** T01, 4.10: query, fragment and trailing slash are ignored for the id and the cache key. */
    public function testS1QueryFragmentAndSlashIgnored(): void
    {
        $detector = new Detector();
        $keys = [];
        foreach (self::urlList()['S1'] as $url) {
            $d = $detector->detect($url);
            self::assertSame(self::ID_A, $d->articleId);
            $keys[] = $d->normalised;
        }
        self::assertSame([self::PAGE_A], array_values(array_unique($keys)));
    }

    /** T05, T06: target parameter is read and decoded once. */
    public function testTargetParameterExtracted(): void
    {
        $detector = new Detector();
        self::assertSame('https://example.com/story/1', $detector->detect(self::urlList()['S5'][0])->target);
        self::assertSame('https://example.com/story/2', $detector->detect(self::urlList()['S6'][0])->target);
        self::assertSame('https://example.co.uk/story/3', $detector->detect(self::urlList()['S6'][1])->target);
    }

    /** T07: S7 is not a wrapper by default, and is one when scope is widened. */
    public function testWebRedirectorScope(): void
    {
        foreach (self::urlList()['S7'] as $url) {
            $narrow = (new Detector())->detect($url);
            self::assertFalse($narrow->isWrapper);
            self::assertSame(Reason::NotGoogleNews, $narrow->reason);

            $wide = (new Detector(true))->detect($url);
            self::assertTrue($wide->isWrapper);
            self::assertSame(Shape::WebRedirector, $wide->shape);
        }
    }

    /** T08: relative links need a Google News base URL. */
    public function testRelativeLinks(): void
    {
        $detector = new Detector();
        self::assertSame(Reason::InvalidUrl, $detector->detect('./articles/' . self::ID_A)->reason);
        self::assertSame(Reason::InvalidUrl, $detector->detect('./articles/' . self::ID_A, 'https://example.com/')->reason);

        $d = $detector->detect('./articles/' . self::ID_A . '?hl=en', 'https://news.google.com/');
        self::assertTrue($d->isWrapper);
        self::assertSame(Shape::Relative, $d->shape);
        self::assertSame(self::ID_A, $d->articleId);
        self::assertSame('https://news.google.com/articles/' . self::ID_A, $d->normalised);

        $read = $detector->detect('./read/' . self::ID_A, 'https://news.google.com/');
        self::assertSame(Shape::Relative, $read->shape);

        self::assertSame(Reason::NotAnArticleLink, $detector->detect('./topics/abc', 'https://news.google.com/')->reason);
    }

    /** T09: locale-prefixed paths are not detected (S9 dropped, see ARCHITECTURE.md). */
    public function testLocalePrefixedPathIsNotAWrapper(): void
    {
        $d = (new Detector())->detect('https://news.google.com/en-IN/articles/' . self::ID_A);
        self::assertFalse($d->isWrapper);
        self::assertSame(Reason::NotAnArticleLink, $d->reason);
    }

    /** T10: Google News pages that are not article links. */
    public function testNotAnArticleLink(): void
    {
        foreach (self::urlList()['not_an_article_link'] as $url) {
            $d = (new Detector())->detect($url);
            self::assertFalse($d->isWrapper, $url);
            self::assertSame(Reason::NotAnArticleLink, $d->reason, $url);
        }
    }

    /** T11 (detection part): publisher URLs are not Google News. */
    public function testPublisherUrlIsNotGoogleNews(): void
    {
        $urls = [
            'https://example.com/story',
            'http://www.example.co.uk/a?b=c',
            'https://www.google.com/search?q=x',
            'https://news.google.co.in/rss/articles/abc',
        ];
        foreach ($urls as $url) {
            $d = (new Detector())->detect($url);
            self::assertFalse($d->isWrapper, $url);
            self::assertSame(Reason::NotGoogleNews, $d->reason, $url);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'no scheme' => ['news.google.com/rss/articles/abc'];
        yield 'protocol relative' => ['//news.google.com/rss/articles/abc'];
        yield 'no host' => ['https:///path'];
        yield 'inner space' => ['https://news.google.com/rss/articles/ab c'];
        yield 'binary' => ["https://news.google.com/\x00\x01\xff"];
        yield 'bad utf8' => ["https://example.com/\xC3\x28"];
        yield 'over 8 KB' => ['https://news.google.com/rss/articles/' . str_repeat('A', 9 * 1024)];
        yield 'ftp scheme' => ['ftp://news.google.com/rss/articles/abc'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'just text' => ['not a url at all'];
    }

    /** T12: junk is invalid_url and never throws. */
    #[DataProvider('invalidInputs')]
    public function testInvalidInput(string $input): void
    {
        $d = (new Detector())->detect($input);
        self::assertFalse($d->isWrapper);
        self::assertSame(Reason::InvalidUrl, $d->reason);
    }

    /** T13: uppercase host and a trailing dot do not change detection. */
    public function testHostCaseAndTrailingDot(): void
    {
        foreach (
            [
            'https://NEWS.Google.COM/rss/articles/' . self::ID_A,
            'https://news.google.com./rss/articles/' . self::ID_A,
            'HTTPS://News.Google.Com./articles/' . self::ID_A,
            ] as $url
        ) {
            $d = (new Detector())->detect($url);
            self::assertTrue($d->isWrapper, $url);
            self::assertSame(self::ID_A, $d->articleId);
        }
    }

    /** T14: ids outside the base64 alphabet are malformed. */
    public function testMalformedId(): void
    {
        foreach (['CBMi$$$', 'CBMi%3Cscript%3E', 'CBMi.abc', 'CBMi==='] as $id) {
            $d = (new Detector())->detect('https://news.google.com/rss/articles/' . $id);
            self::assertFalse($d->isWrapper, $id);
            self::assertSame(Reason::MalformedId, $d->reason, $id);
            self::assertSame(Shape::RssArticles, $d->shape);
        }
        // Padding and both alphabets are fine.
        self::assertTrue((new Detector())->detect('https://news.google.com/rss/articles/CBMi+abc_-x==')->isWrapper);
    }
}
