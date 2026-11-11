<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Attempt;
use IndianOpinion\GnewsUnwrap\LegacyIdDecoder;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Result;
use IndianOpinion\GnewsUnwrap\Shape;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

final class ResultTest extends TestCase
{
    /** T52: fields and the to-array round trip. */
    public function testFieldsAndRoundTrip(): void
    {
        $id = LegacyIdDecoder::encode('https://example.com/a', 'https://example.com/a/amp');
        $r = $this->unwrapper(new FakeTransport($this->clock))->resolve('https://news.google.com/rss/articles/' . $id . '?oc=5');

        self::assertSame('https://news.google.com/rss/articles/' . $id . '?oc=5', $r->input);
        self::assertSame('https://example.com/a', $r->output);
        self::assertTrue($r->resolved);
        self::assertTrue($r->changed());
        self::assertSame(Method::OfflineDecode, $r->method);
        self::assertNull($r->originalMethod);
        self::assertNull($r->reason);
        self::assertEquals([new Attempt(Method::OfflineDecode, null)], $r->attempts);
        self::assertSame(0, $r->requests);
        self::assertSame(0, $r->elapsedMs);
        self::assertSame('example.com', $r->outletKey);
        self::assertFalse($r->fromNegativeCache);
        self::assertSame(Shape::RssArticles, $r->shape);
        self::assertSame('https://example.com/a/amp', $r->ampUrl);

        $array = $r->toArray();
        self::assertSame('offline_decode', $array['method']);
        self::assertSame('S1', $array['shape']);
        self::assertSame(json_decode((string) json_encode($array), true), $array, 'JSON safe');
        self::assertEquals($r, Result::fromArray($array));

        $failed = new Result('x', 'x', false, Method::None, null, Reason::InvalidUrl, [], 0, 0, '');
        self::assertEquals($failed, Result::fromArray($failed->toArray()));
        self::assertFalse($failed->changed());
    }

    public function testFromArrayRejectsJunk(): void
    {
        self::assertNull(Result::fromArray([]));
        self::assertNull(Result::fromArray(['input' => 'a', 'output' => 'b', 'resolved' => true, 'method' => 'teleport']));
        self::assertNull(Result::fromArray(['input' => 'a', 'output' => 'b', 'resolved' => true, 'method' => 'cache', 'attempts' => [['method' => 'nope']]]));
    }
}
