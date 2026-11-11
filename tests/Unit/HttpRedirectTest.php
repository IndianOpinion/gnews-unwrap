<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Exception\TransportException;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;
use IndianOpinion\GnewsUnwrap\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class HttpRedirectTest extends TestCase
{
    private function redirectOnly(): Config
    {
        return (new Config())->withoutMethods(Method::BatchExecute);
    }

    /** T27: one hop to the publisher; the publisher is never requested. */
    public function testRedirectToPublisher(): void
    {
        $transport = $this->transport('http/302-publisher.json');
        $r = $this->unwrapper($transport, $this->redirectOnly())->resolve(self::WRAPPER_B . '?oc=5');
        self::assertTrue($r->resolved);
        self::assertSame('https://www.example.com/politics/2026/11/story.html?utm_source=gnews', $r->output);
        self::assertSame(Method::HttpRedirect, $r->method);
        self::assertSame(['GET ' . self::WRAPPER_B], $transport->requestLines());
        self::assertSame('example.com', $r->outletKey);
    }

    /** The batch page fetch and http_redirect share one request for S1 links. */
    public function testBatchPageResponseIsReused(): void
    {
        $transport = $this->transport('http/302-publisher.json');
        $r = $this->unwrapper($transport)->resolve(self::WRAPPER_B);
        self::assertTrue($r->resolved);
        self::assertSame(Method::HttpRedirect, $r->method);
        self::assertSame(Reason::UnexpectedStatus, $r->attempts[1]->reason);
        self::assertCount(1, $transport->requests());
    }

    /** T28: chain within the limit. */
    public function testChainWithinLimit(): void
    {
        $transport = $this->transport('http/chain-3.json');
        $r = $this->unwrapper($transport, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame('https://example.com/a/b', $r->output);
        self::assertSame(3, $r->requests);
    }

    /** T29: too many redirects. */
    public function testTooManyRedirects(): void
    {
        $transport = $this->transport('http/chain-12.json');
        $r = $this->unwrapper($transport, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertFalse($r->resolved);
        self::assertSame(Reason::TooManyRedirects, $r->reason);
        self::assertSame(6, $r->requests);

        $this->setUp();
        $zero = $this->unwrapper($this->transport('http/302-publisher.json'), $this->redirectOnly()->withMaxRedirects(0))->resolve(self::WRAPPER_B);
        self::assertSame(Reason::TooManyRedirects, $zero->reason);
    }

    /** T30: a hop seen before stops the resolution. */
    public function testRedirectLoop(): void
    {
        $r = $this->unwrapper($this->transport('http/loop.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::RedirectLoop, $r->reason);
        self::assertSame(3, $r->requests);
    }

    /** T31: relative, protocol-relative and spaced Location headers. */
    public function testRelativeLocations(): void
    {
        $transport = $this->transport('http/relative.json');
        $r = $this->unwrapper($transport, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertTrue($r->resolved);
        self::assertSame('https://example.org/path%20with%20space/story?id=7', $r->output);
        self::assertSame('GET https://news.google.com/hop/rel?x=1', $transport->requestLines()[1]);
    }

    /** @return iterable<string, array{string, Reason, bool}> */
    public static function statusCases(): iterable
    {
        yield '403' => ['http/403.json', Reason::Forbidden, true];
        yield '404' => ['http/404.json', Reason::Gone, true];
        yield '410' => ['http/410.json', Reason::Gone, true];
        yield '503' => ['http/503.json', Reason::UpstreamError, false];
    }

    /** T37: 403, 404, 410 and 5xx reasons, and which of them are cached. */
    #[DataProvider('statusCases')]
    public function testStatusReasons(string $fixture, Reason $reason, bool $cached): void
    {
        $transport = $this->transport($fixture);
        $u = $this->unwrapper($transport, $this->redirectOnly()->withoutMethods(Method::BatchExecute, Method::HtmlParse));
        $first = $u->resolve(self::WRAPPER_B);
        self::assertSame($reason, $first->reason);
        $second = $u->resolve(self::WRAPPER_B);
        self::assertSame($cached, $second->fromNegativeCache);
        self::assertCount($cached ? 1 : 2, $transport->requests());
    }

    /** T38: consent wall with and without a usable continue parameter. */
    public function testConsentWall(): void
    {
        $ok = $this->unwrapper($this->transport('http/consent-continue.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertTrue($ok->resolved);
        self::assertSame('https://example.com/news/consent-story', $ok->output);

        $this->setUp();
        $wall = $this->unwrapper($this->transport('http/consent-plain.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertFalse($wall->resolved);
        self::assertSame(Reason::ConsentWall, $wall->reason);
    }

    /** T39: sign-in hop. */
    public function testLoginWall(): void
    {
        $r = $this->unwrapper($this->transport('http/accounts.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::LoginWall, $r->reason);
    }

    /** T40: non-HTML 200. */
    public function testNonHtml(): void
    {
        $r = $this->unwrapper($this->transport('http/pdf.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::UnexpectedContentType, $r->reason);
    }

    /** T41: javascript:, data: and file: targets are rejected. */
    public function testBadSchemes(): void
    {
        foreach (['http/bad-scheme.json', 'http/data-scheme.json', 'http/file-scheme.json'] as $fixture) {
            $this->setUp();
            $r = $this->unwrapper($this->transport($fixture), $this->redirectOnly())->resolve(self::WRAPPER_B);
            self::assertFalse($r->resolved, $fixture);
            self::assertSame(Reason::BadTarget, $r->reason, $fixture);
        }
    }

    /** T50: a wrapper pointing at a non-news site is still a valid resolution. */
    public function testVideoTarget(): void
    {
        $r = $this->unwrapper($this->transport('http/302-video.json'), $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertTrue($r->resolved);
        self::assertSame('youtube.com', $r->outletKey);
    }

    /** 4.3: https to http on the final publisher hop is allowed and recorded. */
    public function testInsecureFinalHop(): void
    {
        $transport = (new FakeTransport($this->clock))->redirect(self::WRAPPER_B, 'http://example.com/plain');
        $r = $this->unwrapper($transport, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertTrue($r->resolved);
        self::assertTrue($r->insecureTarget);
    }

    /** 4.10: an http wrapper is requested over https. */
    public function testHttpWrapperRequestedOverHttps(): void
    {
        $transport = $this->transport('http/302-publisher.json');
        $this->unwrapper($transport, $this->redirectOnly())->resolve('http://news.google.com/rss/articles/' . self::ID_B);
        self::assertSame(['GET ' . self::WRAPPER_B], $transport->requestLines());
    }

    /** T35: connect timeout, request timeout and the per-link budget. */
    public function testTimeouts(): void
    {
        $connect = (new FakeTransport($this->clock))->on(self::WRAPPER_B, new Response(200, [], ''), 'GET', 0.0, 5.0);
        $r = $this->unwrapper($connect, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::Timeout, $r->reason);

        $this->setUp();
        $slow = (new FakeTransport($this->clock))->on(self::WRAPPER_B, new Response(200, [], ''), 'GET', 9.0);
        $r = $this->unwrapper($slow, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::Timeout, $r->reason);
        self::assertSame(8000, $r->elapsedMs);

        $this->setUp();
        $budget = new FakeTransport($this->clock);
        $budget->on(self::WRAPPER_B, new Response(302, ['Location' => 'https://news.google.com/h/1'], ''), 'GET', 7.0);
        $budget->on('https://news.google.com/h/1', new Response(302, ['Location' => 'https://news.google.com/h/2'], ''), 'GET', 7.0);
        $budget->on('https://news.google.com/h/2', new Response(302, ['Location' => 'https://example.com/late'], ''), 'GET', 7.0);
        $r = $this->unwrapper($budget, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::BudgetExceeded, $r->reason);
        self::assertLessThanOrEqual(15000, $r->elapsedMs);
        self::assertSame(1.0, $budget->requests()[2]->timeout, 'the last request only gets what is left of the budget');
    }

    /** T36: network errors and transport exceptions become results. */
    public function testNetworkErrors(): void
    {
        $fail = (new FakeTransport($this->clock))->on(self::WRAPPER_B, TransportException::network('connection refused'));
        $r = $this->unwrapper($fail, $this->redirectOnly())->resolve(self::WRAPPER_B);
        self::assertSame(Reason::NetworkError, $r->reason);

        $this->setUp();
        $throwing = new class () implements \IndianOpinion\GnewsUnwrap\Transport\Transport {
            public function send(\IndianOpinion\GnewsUnwrap\Transport\Request $request): Response
            {
                throw new \RuntimeException('boom');
            }
        };
        $u = new \IndianOpinion\GnewsUnwrap\Unwrapper(null, $throwing, null, $this->clock);
        $r = $u->resolve(self::WRAPPER_B);
        self::assertFalse($r->resolved);
        self::assertSame(Reason::NetworkError, $r->reason);
        // Transient: not cached, a retry makes requests again.
        self::assertFalse($u->resolve(self::WRAPPER_B)->fromNegativeCache);
    }
}
