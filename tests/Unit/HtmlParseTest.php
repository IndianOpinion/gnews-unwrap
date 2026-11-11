<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\HtmlTargetExtractor;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\TargetValidator;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;
use IndianOpinion\GnewsUnwrap\Transport\Response;

final class HtmlParseTest extends TestCase
{
    private function extract(string $file): ?string
    {
        $html = (string) file_get_contents(self::fixture('html/' . $file));

        return (new HtmlTargetExtractor(new TargetValidator()))->extract($html, self::WRAPPER_B, self::WRAPPER_B);
    }

    /** T32: meta refresh, through the resolver. */
    public function testMetaRefresh(): void
    {
        $transport = $this->transport('http/interstitial.json');
        $r = $this->unwrapper($transport, (new Config())->withoutMethods(Method::BatchExecute))->resolve(self::WRAPPER_B);
        self::assertTrue($r->resolved);
        self::assertSame(Method::HtmlParse, $r->method);
        self::assertSame('https://example.com/business/2026/11/rupee-closes-higher', $r->output);
        self::assertSame(Reason::NotRedirected, $r->attempts[1]->reason);
        self::assertSame(1, $r->requests);
    }

    /** T33: canonical, single anchor, location literals; Google-only candidates rejected. */
    public function testOtherCandidates(): void
    {
        self::assertSame('https://example.co.uk/sport/cricket/test-match-report', $this->extract('canonical.html'));
        self::assertSame('https://example.net/india/2026/11/monsoon-update?id=55&ref=gn', $this->extract('anchor.html'));
        self::assertNull($this->extract('anchor-many.html'), 'two different anchors are ambiguous');
        self::assertSame('https://example.com/tech/2026/chip-plant?src=gn', $this->extract('location.html'));
        self::assertSame('https://example.in/state/kerala/flood-relief', $this->extract('location-replace.html'));
        self::assertNull($this->extract('google-only.html'));
    }

    /** html_parse also reads the page batch_execute fetched. */
    public function testParsesBatchPage(): void
    {
        $page = '<html><body><div data-n-a-id="x"></div><a href="https://example.org/from-batch-page">Read</a></body></html>';
        $transport = (new FakeTransport($this->clock))
            ->on(self::PAGE_A, new Response(200, ['Content-Type' => 'text/html'], $page));
        $r = $this->unwrapper($transport, (new Config())->withoutMethods(Method::HttpRedirect))->resolve(self::PAGE_A);
        self::assertSame('https://example.org/from-batch-page', $r->output);
        self::assertSame(Method::HtmlParse, $r->method);
        self::assertSame(Reason::SignatureMissing, $r->attempts[1]->reason);
    }

    /** T34: only the first 512 KB is read. */
    public function testBodyCap(): void
    {
        $late = str_repeat('<p>padding</p>', 40_000) . '<meta http-equiv="refresh" content="0;url=https://example.com/late">';
        self::assertGreaterThan(524_288, strlen($late));
        $extractor = new HtmlTargetExtractor(new TargetValidator());
        self::assertNull($extractor->extract($late, self::WRAPPER_B));

        $early = '<meta http-equiv="refresh" content="0;url=https://example.com/early">' . str_repeat('x', 700_000);
        $transport = (new FakeTransport($this->clock))
            ->on(self::WRAPPER_B, new Response(200, ['Content-Type' => 'text/html'], $early));
        $r = $this->unwrapper($transport, (new Config())->withoutMethods(Method::BatchExecute))->resolve(self::WRAPPER_B);
        self::assertSame('https://example.com/early', $r->output);
        self::assertSame(524_288, $transport->requests()[0]->maxBodyBytes);
    }
}
