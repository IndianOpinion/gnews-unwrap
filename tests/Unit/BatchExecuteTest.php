<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\BatchExecute;
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;

final class BatchExecuteTest extends TestCase
{
    /** T23: happy path, two requests, JSON parsed. */
    public function testHappyPath(): void
    {
        $transport = $this->transport('batch/ok.json');
        $r = $this->unwrapper($transport)->resolve(self::PAGE_A . '?oc=5');

        self::assertTrue($r->resolved);
        self::assertSame('https://example.com/news/2026/11/11/budget-session-opens', $r->output);
        self::assertSame(Method::BatchExecute, $r->method);
        self::assertSame(2, $r->requests);
        self::assertSame('example.com', $r->outletKey);
        self::assertSame(['GET ' . self::PAGE_A, 'POST ' . self::ENDPOINT], $transport->requestLines());

        $post = $transport->requests()[1];
        self::assertNotNull($post->body);
        self::assertStringStartsWith('f.req=', $post->body);
        $freq = json_decode(rawurldecode(substr($post->body, 6)), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($freq);
        self::assertSame('Fbv4je', $freq[0][0][0]);
        $inner = json_decode($freq[0][0][1], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($inner);
        self::assertSame('garturlreq', $inner[0]);
        self::assertSame(self::ID_A, $inner[2]);
        self::assertSame(1731300000, $inner[3]);
        self::assertSame('AZ5r3eT3stS1gnatur3_-Value', $inner[4]);
        self::assertSame('application/x-www-form-urlencoded;charset=UTF-8', $post->headers['Content-Type']);
    }

    public function testChunkedResponse(): void
    {
        $r = $this->unwrapper($this->transport('batch/chunked.json'))->resolve(self::PAGE_A);
        self::assertSame('https://example.co.uk/world/story-42', $r->output);
    }

    /** T24: missing signature attributes. */
    public function testSignatureMissing(): void
    {
        $transport = $this->transport('batch/nosig.json');
        $config = (new Config())->withoutMethods(Method::HttpRedirect, Method::HtmlParse);
        $r = $this->unwrapper($transport, $config)->resolve(self::PAGE_A);
        self::assertFalse($r->resolved);
        self::assertSame(Reason::SignatureMissing, $r->reason);
        self::assertCount(1, $transport->requests());
    }

    /** T25: 429 and the sorry redirect stop all network methods. */
    public function testRateLimited(): void
    {
        foreach (['http/429.json', 'http/sorry.json'] as $fixture) {
            $this->setUp();
            $transport = $this->transport($fixture);
            $r = $this->unwrapper($transport)->resolve(self::PAGE_A);
            self::assertFalse($r->resolved, $fixture);
            self::assertSame(Reason::RateLimited, $r->reason, $fixture);
            self::assertCount(1, $transport->requests(), 'no request after the first 429: ' . $fixture);
            self::assertNotContains(Method::HttpRedirect, array_map(static fn ($a) => $a->method, $r->attempts));
        }
    }

    /** T26: a changed response shape is reported loudly. */
    public function testFormatChanged(): void
    {
        $config = (new Config())->withoutMethods(Method::HttpRedirect, Method::HtmlParse);
        $r = $this->unwrapper($this->transport('batch/changed.json'), $config)->resolve(self::PAGE_A);
        self::assertSame(Reason::FormatChanged, $r->reason);

        foreach (['', ")]}'", ")]}'\n\nnot json", ")]}'\n[[\"wrb.fr\",\"Fbv4je\",null]]", '[["wrb.fr","Fbv4je","[\"garturlres\"]"]]', '[["di",1]]'] as $body) {
            self::assertNull(BatchExecute::parseResponse($body), $body);
        }
    }

    public function testPageParams(): void
    {
        self::assertNull(BatchExecute::pageParams('<div data-n-a-sg="abc" data-n-a-ts="notdigits">'));
        self::assertNull(BatchExecute::pageParams('<div data-n-a-sg="" data-n-a-ts="1">'));
        $p = BatchExecute::pageParams("<div data-n-a-ts='42' data-n-a-sg='s1g'>");
        self::assertSame(['signature' => 's1g', 'timestamp' => '42', 'id' => null], $p);
    }

    public function testBatchResultMustPassAcceptance(): void
    {
        $transport = $this->transport('batch/ok.json');
        $config = (new Config())->withOwnHosts(['example.com'])->withoutMethods(Method::HttpRedirect, Method::HtmlParse);
        $r = $this->unwrapper($transport, $config)->resolve(self::PAGE_A);
        self::assertFalse($r->resolved);
        self::assertSame(Reason::BadTarget, $r->reason);
    }
}
