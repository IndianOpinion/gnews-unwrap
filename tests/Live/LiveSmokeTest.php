<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Live;

use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Transport\Request;
use IndianOpinion\GnewsUnwrap\Unwrapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T70: opt-in live smoke test. Talks to Google. Never runs in CI.
 *
 *     GNEWS_UNWRAP_LIVE=1 vendor/bin/phpunit --group live
 *
 * Optional: GNEWS_UNWRAP_LIVE_URL=<a wrapper link> to test one link instead
 * of the first item of a Google News feed.
 */
#[Group('live')]
final class LiveSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('GNEWS_UNWRAP_LIVE') !== '1') {
            self::markTestSkipped('Set GNEWS_UNWRAP_LIVE=1 to run the live smoke test.');
        }
    }

    public function testResolvesALiveWrapper(): void
    {
        $unwrapper = new Unwrapper((new Config())->withPauseMs(500));
        $url = getenv('GNEWS_UNWRAP_LIVE_URL');
        if (!is_string($url) || $url === '') {
            $url = $this->firstFeedLink();
        }

        $result = $unwrapper->resolve($url);
        fwrite(STDERR, PHP_EOL . json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        if ($result->reason === Reason::RateLimited || $result->reason === Reason::CircuitOpen) {
            self::markTestIncomplete('Google rate-limited this machine; try later.');
        }
        if ($result->reason === Reason::ConsentWall) {
            self::markTestIncomplete('Google sent this machine to its consent page (usual from EU and UK addresses); run from elsewhere.');
        }
        self::assertTrue($result->resolved, (string) json_encode($result->toArray()));
        self::assertNotSame('news.google.com', $result->outletKey);
        self::assertContains($result->method, [Method::OfflineDecode, Method::BatchExecute, Method::HttpRedirect, Method::HtmlParse]);

        $again = $unwrapper->resolve($url);
        self::assertSame(Method::Cache, $again->method);
        self::assertSame(0, $again->requests);
    }

    private function firstFeedLink(): string
    {
        $feed = Unwrapper::defaultTransport()->send(new Request(
            'GET',
            'https://news.google.com/rss?hl=en-IN&gl=IN&ceid=IN:en',
            ['User-Agent' => 'gnews-unwrap live smoke test'],
        ));
        self::assertSame(200, $feed->status, 'feed fetch');
        if (preg_match('#<link>(https://news\.google\.com/rss/articles/[^<]+)</link>#', $feed->body, $m) !== 1) {
            self::fail('No wrapper link found in the feed.');
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
    }
}
