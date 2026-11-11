<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\LegacyIdDecoder;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

final class LegacyIdDecoderTest extends TestCase
{
    /** T17: legacy id with a one-byte length decodes offline. */
    public function testOneByteLength(): void
    {
        $url = 'https://example.com/news/story-1';
        $id = LegacyIdDecoder::encode($url);
        self::assertStringStartsWith('CBMi', $id);
        $decoded = (new LegacyIdDecoder())->decode($id);
        self::assertSame($url, $decoded->url);
        self::assertNull($decoded->reason);

        $transport = new FakeTransport($this->clock);
        $r = $this->unwrapper($transport)->resolve('https://news.google.com/rss/articles/' . $id . '?oc=5');
        self::assertTrue($r->resolved);
        self::assertSame(Method::OfflineDecode, $r->method);
        self::assertSame(0, $r->requests);
        self::assertSame([], $transport->requests());
    }

    /** T18: URL over 127 bytes needs a two-byte varint length. */
    public function testTwoByteVarint(): void
    {
        $url = 'https://example.co.uk/' . str_repeat('long-path-segment/', 12) . 'story.html';
        self::assertGreaterThan(127, strlen($url));
        $decoded = (new LegacyIdDecoder())->decode(LegacyIdDecoder::encode($url));
        self::assertSame($url, $decoded->url);
    }

    /** T19, Q8: AMP second field is exposed but never preferred. */
    public function testAmpField(): void
    {
        $url = 'https://example.com/news/story-1';
        $amp = 'https://example.com/news/story-1/amp';
        $id = LegacyIdDecoder::encode($url, $amp);
        $decoded = (new LegacyIdDecoder())->decode($id);
        self::assertSame($url, $decoded->url);
        self::assertSame($amp, $decoded->ampUrl);

        $r = $this->unwrapper(new FakeTransport($this->clock))->resolve('https://news.google.com/articles/' . $id);
        self::assertSame($url, $r->output);
        self::assertSame($amp, $r->ampUrl);
    }

    /** T20: opaque AU_yqL id gives opaque_id and the next method runs. */
    public function testOpaqueId(): void
    {
        self::assertSame(Reason::OpaqueId, (new LegacyIdDecoder())->decode(self::ID_A)->reason);

        $transport = $this->transport('batch/ok.json');
        $r = $this->unwrapper($transport)->resolve(self::PAGE_A . '?oc=5');
        self::assertTrue($r->resolved);
        self::assertSame(Method::BatchExecute, $r->method);
        self::assertSame(Method::OfflineDecode, $r->attempts[0]->method);
        self::assertSame(Reason::OpaqueId, $r->attempts[0]->reason);
    }

    /** T21: padded, unpadded, standard and URL-safe alphabets. */
    public function testAlphabetsAndPadding(): void
    {
        // A URL whose encoding contains both '-'/'_' (URL-safe) characters.
        $url = 'https://example.com/?q=>>>???~~~';
        $urlSafe = LegacyIdDecoder::encode($url);
        self::assertMatchesRegularExpression('/[-_]/', $urlSafe);
        $standard = strtr($urlSafe, '-_', '+/');
        $padded = $urlSafe . str_repeat('=', (4 - strlen($urlSafe) % 4) % 4);

        $decoder = new LegacyIdDecoder();
        foreach ([$urlSafe, $standard, $padded] as $id) {
            self::assertSame($url, $decoder->decode($id)->url, $id);
        }
    }

    /** Q2: any prefix other than the documented layout is unknown and goes to the network. */
    public function testUnknownPrefix(): void
    {
        $decoder = new LegacyIdDecoder();
        $other = rtrim(strtr(base64_encode("\x12\x05hello"), '+/', '-_'), '=');
        self::assertSame(Reason::UnknownIdFormat, $decoder->decode($other)->reason);
        // Field 1 then a varint field instead of field 4.
        $noString = rtrim(strtr(base64_encode("\x08\x13\x10\x01"), '+/', '-_'), '=');
        self::assertSame(Reason::UnknownIdFormat, $decoder->decode($noString)->reason);
        // Other field-1 values ("CAIi" and friends) still decode.
        self::assertSame('https://example.com/x', $decoder->decode(LegacyIdDecoder::encode('https://example.com/x', null, 2))->url);
    }

    /** T22: truncated or hostile protobuf does not crash and stays bounded. */
    public function testHostileInput(): void
    {
        $decoder = new LegacyIdDecoder();
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $cases = [
            $b64("\x08"),
            $b64("\x08\x13"),
            $b64("\x08\x13\x22"),
            $b64("\x08\x13\x22\xff\xff\xff\xff\x0f"),
            $b64("\x08\x13\x22\x7fhttps://ex"),
            $b64("\x08\xff\xff\xff\xff\xff\xff\x22\x01a"),
            $b64("\x08\x13\x22\x05https\x07\x07\x07"),
            $b64(str_repeat("\xff", 300)),
            'A',
            str_repeat('A', 5000),
            '',
        ];
        $before = memory_get_usage();
        foreach ($cases as $id) {
            $decoded = $decoder->decode($id);
            self::assertNull($decoded->url, $id);
            self::assertNotNull($decoded->reason);
        }
        self::assertLessThan(1_000_000, memory_get_usage() - $before);

        for ($i = 0; $i < 300; $i++) {
            $decoder->decode($b64(random_bytes(random_int(1, 200))));
        }
        $this->addToAssertionCount(300);
    }
}
