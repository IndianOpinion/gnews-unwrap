<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\AddressGuard;
use IndianOpinion\GnewsUnwrap\Cache\ArrayCache;
use IndianOpinion\GnewsUnwrap\Cache\NullCache;
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Exception\TransportContractException;
use IndianOpinion\GnewsUnwrap\Exception\TransportException;
use IndianOpinion\GnewsUnwrap\LegacyIdDecoder;
use IndianOpinion\GnewsUnwrap\Log\MemoryLogger;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Reason;
use IndianOpinion\GnewsUnwrap\Tests\Support\BrokenConcurrentTransport;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;
use IndianOpinion\GnewsUnwrap\Transport\FakeConcurrentTransport;
use IndianOpinion\GnewsUnwrap\Transport\ConcurrentTransport;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;
use IndianOpinion\GnewsUnwrap\Transport\Request;
use IndianOpinion\GnewsUnwrap\Transport\Response;
use IndianOpinion\GnewsUnwrap\Unwrapper;

final class UnwrapperTest extends TestCase
{
    /** T11: publisher URL: not_google_news, zero requests, not cached. */
    public function testPublisherUrlIsUntouched(): void
    {
        $transport = new FakeTransport($this->clock);
        $cache = new ArrayCache(10, $this->clock);
        $u = $this->unwrapper($transport, null, $cache);
        $r = $u->resolve('https://www.example.com/story?id=1');
        self::assertFalse($r->resolved);
        self::assertSame(Method::None, $r->method);
        self::assertSame(Reason::NotGoogleNews, $r->reason);
        self::assertSame('https://www.example.com/story?id=1', $r->output);
        self::assertSame('example.com', $r->outletKey);
        self::assertSame(0, $r->requests);
        self::assertSame([], $transport->requests());
        self::assertSame(0, $cache->count());
    }

    /** T44: method order is respected, and each method can be switched off. */
    public function testMethodOrderAndSwitches(): void
    {
        $legacy = 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/legacy');
        $transport = $this->transport('batch/ok.json');
        $u = $this->unwrapper($transport);
        self::assertSame(Method::OfflineDecode, $u->resolve($legacy)->method);
        self::assertSame([], $transport->requests(), 'offline decode runs before any network method');

        $noOffline = $this->unwrapper($transport, (new Config())->withoutMethods(Method::OfflineDecode, Method::Cache));
        $transport->load(self::fixture('http/302-publisher.json'));
        // Legacy id has no batch fixture: batch fails, then http_redirect has no route either.
        $r = $noOffline->resolve($legacy);
        self::assertFalse($r->resolved);
        self::assertSame([Method::BatchExecute, Method::HttpRedirect], array_map(static fn ($a) => $a->method, $r->attempts));

        $this->setUp();
        $t2 = $this->transport('batch/ok.json');
        $order = $this->unwrapper($t2)->resolve(self::PAGE_A);
        self::assertSame([Method::OfflineDecode, Method::BatchExecute], array_map(static fn ($a) => $a->method, $order->attempts));

        $this->setUp();
        $t3 = $this->transport('http/302-publisher.json');
        $none = $this->unwrapper($t3, new Config(methods: []))->resolve(self::WRAPPER_B);
        self::assertFalse($none->resolved);
        self::assertSame(Reason::NoCandidate, $none->reason);
        self::assertSame([], $t3->requests());
    }

    /** T45: every method fails: the input comes back with every reason listed. */
    public function testAllMethodsFail(): void
    {
        $transport = (new FakeTransport($this->clock))
            ->on(self::PAGE_A, new Response(200, ['Content-Type' => 'text/html'], '<html>nothing here</html>'));
        $r = $this->unwrapper($transport)->resolve(self::PAGE_A . '?oc=5');
        self::assertFalse($r->resolved);
        self::assertSame(self::PAGE_A . '?oc=5', $r->output);
        self::assertSame(Method::None, $r->method);
        self::assertSame(Reason::NoCandidate, $r->reason);
        self::assertSame(
            [Reason::OpaqueId, Reason::SignatureMissing, Reason::NotRedirected, Reason::NoCandidate],
            $r->reasons()
        );
        self::assertSame('news.google.com', $r->outletKey);
    }

    /** T46: second call is a cache hit with zero requests, original method recorded. */
    public function testCacheHit(): void
    {
        $transport = $this->transport('batch/ok.json');
        $u = $this->unwrapper($transport);
        $first = $u->resolve(self::PAGE_A . '?oc=5');
        $second = $u->resolve('https://news.google.com/rss/articles/' . self::ID_A . '?hl=en-IN&gl=IN');
        self::assertSame(Method::Cache, $second->method);
        self::assertSame(Method::BatchExecute, $second->originalMethod);
        self::assertSame(0, $second->requests);
        self::assertSame($first->output, $second->output);
        self::assertSame(2, count($transport->requests()));
        self::assertSame(1, $u->stats()['cache_hits']);
    }

    /** T47: negative lifetime, and rate_limited never cached. */
    public function testNegativeCaching(): void
    {
        $transport = $this->transport('http/404.json');
        $u = $this->unwrapper($transport, (new Config())->withoutMethods(Method::BatchExecute));
        self::assertSame(Reason::Gone, $u->resolve(self::WRAPPER_B)->reason);
        $hit = $u->resolve(self::WRAPPER_B);
        self::assertTrue($hit->fromNegativeCache);
        self::assertSame(Reason::Gone, $hit->reason);
        self::assertSame(self::WRAPPER_B, $hit->output);
        self::assertCount(1, $transport->requests());

        $this->clock->advance(3601);
        self::assertFalse($u->resolve(self::WRAPPER_B)->fromNegativeCache);
        self::assertCount(2, $transport->requests());

        $this->setUp();
        $limited = $this->transport('http/429.json');
        $u = $this->unwrapper($limited, (new Config())->withCircuitBreaker(10, 60, 300));
        $u->resolve(self::PAGE_A);
        $u->resolve(self::PAGE_A);
        self::assertCount(2, $limited->requests(), 'rate_limited is not cached');
    }

    /** T48: the circuit breaker opens after 3 rate limits in 60 s and closes after 5 minutes. */
    public function testCircuitBreaker(): void
    {
        $transport = $this->transport('http/429.json');
        $legacy = 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/offline-still-works');
        $u = $this->unwrapper($transport);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(Reason::RateLimited, $u->resolve(self::PAGE_A)->reason);
            $this->clock->advance(10);
        }
        self::assertTrue($u->circuitBreaker()->isOpen());

        $skipped = $u->resolve(self::PAGE_A);
        self::assertSame(Reason::CircuitOpen, $skipped->reason);
        self::assertSame(0, $skipped->requests);
        self::assertCount(3, $transport->requests());

        // Offline methods still run while the breaker is open.
        self::assertTrue($u->resolve($legacy)->resolved);

        $this->clock->advance(301);
        self::assertFalse($u->circuitBreaker()->isOpen());
        $u->resolve(self::PAGE_A);
        self::assertCount(4, $transport->requests());
    }

    public function testBreakerWindow(): void
    {
        $transport = $this->transport('http/429.json');
        $u = $this->unwrapper($transport);
        for ($i = 0; $i < 3; $i++) {
            $u->resolve(self::PAGE_A);
            $this->clock->advance(31);
        }
        self::assertFalse($u->circuitBreaker()->isOpen(), 'three hits spread over more than 60 s do not open it');
    }

    /** T49: generic mode is off by default, follows a shortener when on, and refuses private addresses. */
    public function testGenericMode(): void
    {
        $short = 'https://sho.rt/abc';
        $transport = (new FakeTransport($this->clock))
            ->redirect($short, 'https://www.example.com/full-story')
            ->on('https://www.example.com/full-story', new Response(200, ['Content-Type' => 'text/html'], '<html>'));

        $off = $this->unwrapper($transport)->resolve($short);
        self::assertSame(Reason::NotGoogleNews, $off->reason);
        self::assertSame([], $transport->requests());

        $on = $this->unwrapper($transport, (new Config())->withGenericMode(true))->resolve($short);
        self::assertTrue($on->resolved);
        self::assertSame('https://www.example.com/full-story', $on->output);
        self::assertSame(Method::HttpRedirect, $on->method);
        self::assertSame(1024, $transport->requests()[1]->maxBodyBytes);

        $perCall = $this->unwrapper($transport)->resolve($short, null, true);
        self::assertTrue($perCall->resolved);

        $noRedirect = $this->unwrapper($transport, (new Config())->withGenericMode(true))->resolve('https://www.example.com/full-story');
        self::assertFalse($noRedirect->resolved);
        self::assertSame(Reason::NotRedirected, $noRedirect->reason);

        // SSRF: a hop to a metadata address is refused before any request is sent to it.
        $this->setUp();
        $ssrf = (new FakeTransport($this->clock))->redirect($short, 'http://169.254.169.254/latest/meta-data/');
        $r = $this->unwrapper($ssrf, (new Config())->withGenericMode(true))->resolve($short);
        self::assertSame(Reason::PrivateAddress, $r->reason);
        self::assertCount(1, $ssrf->requests());

        $dns = (new FakeTransport($this->clock))->redirect($short, 'https://internal.example/admin');
        $guard = new AddressGuard(static fn (string $h): array => $h === 'internal.example' ? ['10.0.0.8'] : ['93.184.215.14']);
        $r = $this->unwrapper($dns, (new Config())->withGenericMode(true), null, null, $guard)->resolve($short);
        self::assertSame(Reason::PrivateAddress, $r->reason);

        $direct = $this->unwrapper(new FakeTransport($this->clock), (new Config())->withGenericMode(true))->resolve('http://127.0.0.1/');
        self::assertSame(Reason::PrivateAddress, $direct->reason);
        self::assertSame(0, $direct->requests);
    }

    /** T51: two wrappers for one article give the same output and article key. */
    public function testTwoWrappersSameArticle(): void
    {
        $transport = $this->transport('batch/ok.json');
        $transport->redirect(self::WRAPPER_B, 'https://www.example.com/news/2026/11/11/budget-session-opens/?utm_source=gn');
        $u = $this->unwrapper($transport);
        $a = $u->resolve('https://news.google.com/articles/' . self::ID_A . '?hl=en-IN');
        $b = $u->resolve(self::WRAPPER_B);
        self::assertTrue($a->resolved);
        self::assertTrue($b->resolved);
        self::assertSame($u->articleKey($a->output), $u->articleKey($b->output));
        self::assertSame('example.com/news/2026/11/11/budget-session-opens', $u->articleKey($a->output));

        // An AMP variant matches only when the caller turns AMP stripping on.
        $amp = 'https://amp.example.com/news/2026/11/11/budget-session-opens/amp/';
        self::assertNotSame($u->articleKey($a->output), $u->articleKey($amp));
        $ampKeys = $this->unwrapper($transport, (new Config())->withStripAmpMarkers(true));
        self::assertSame($ampKeys->articleKey($a->output), $ampKeys->articleKey($amp));
    }

    /** T53: list resolution keeps order and keys, sequential and concurrent. */
    public function testResolveManySequential(): void
    {
        $legacy = 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/legacy');
        $inputs = ['https://example.org/plain', self::PAGE_A, $legacy, 'junk', self::PAGE_A];
        $transport = $this->transport('batch/ok.json');
        $u = $this->unwrapper($transport, (new Config())->withPauseMs(250));
        $results = $u->resolveMany($inputs);
        self::assertSame(['https://example.org/plain', self::PAGE_A, $legacy, 'junk'], array_keys($results));
        self::assertSame('https://example.org/plain', $results['https://example.org/plain']->output);
        self::assertTrue($results[self::PAGE_A]->resolved);
        self::assertSame('https://example.com/legacy', $results[$legacy]->output);
        self::assertSame(Reason::InvalidUrl, $results['junk']->reason);
        self::assertSame([], $this->clock->sleeps(), 'only one link used the network, so no pause');

        $transport->load(self::fixture('http/302-publisher.json'));
        $u->resolveMany([self::WRAPPER_B, 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/z')]);
        self::assertSame([250], $this->clock->sleeps(), 'pause before the next network resolution');
    }

    public function testResolveManyConcurrent(): void
    {
        $transport = new FakeConcurrentTransport($this->clock);
        $transport->load(self::fixture('batch/ok.json'));
        $transport->load(self::fixture('http/302-publisher.json'));
        $legacy = 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/legacy');
        $u = $this->unwrapper($transport, (new Config())->withConcurrency(4));
        $results = $u->resolveMany([self::PAGE_A, 'https://example.org/x', self::WRAPPER_B, $legacy]);

        self::assertSame([self::PAGE_A, 'https://example.org/x', self::WRAPPER_B, $legacy], array_keys($results));
        self::assertSame('https://example.com/news/2026/11/11/budget-session-opens', $results[self::PAGE_A]->output);
        self::assertSame('https://www.example.com/politics/2026/11/story.html?utm_source=gnews', $results[self::WRAPPER_B]->output);
        self::assertSame(Method::OfflineDecode, $results[$legacy]->method);
        // Round 1: both batch page GETs; round 2: one POST (the other link went to http_redirect from memo).
        self::assertSame([2, 1], $transport->batchSizes());

        $sequential = $this->unwrapper($transport, (new Config())->withConcurrency(1));
        $sequential->resolveMany([self::PAGE_A]);
        self::assertSame([2, 1], $transport->batchSizes(), 'concurrency 1 never calls sendMany');
    }

    /** T67: self-heal: only wrappers change, keys recomputed from the resolved URL. */
    public function testSelfHeal(): void
    {
        $stored = [
            ['id' => 1, 'url' => 'https://www.example.com/a/story?utm_source=x'],
            ['id' => 2, 'url' => self::PAGE_A . '?oc=5'],
            ['id' => 3, 'url' => 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://m.example.co.uk/b/') . '?oc=5'],
            ['id' => 4, 'url' => 'https://news.google.com/topics/abc'],
        ];
        $transport = $this->transport('batch/ok.json');
        $u = $this->unwrapper($transport);
        $updates = [];
        foreach ($stored as $row) {
            $r = $u->resolve($row['url']);
            if ($r->resolved && $r->changed()) {
                $updates[$row['id']] = ['url' => $r->output, 'outlet' => $r->outletKey, 'key' => $u->articleKey($r->output)];
            }
        }
        self::assertSame([2, 3], array_keys($updates));
        self::assertSame('example.com', ($updates[2] ?? [])['outlet'] ?? null);
        self::assertSame('example.co.uk', ($updates[3] ?? [])['outlet'] ?? null);
        self::assertSame('example.co.uk/b', ($updates[3] ?? [])['key'] ?? null);
        self::assertCount(2, $transport->requests());
    }

    public function testLoggerAndStats(): void
    {
        $logger = new MemoryLogger();
        $u = $this->unwrapper($this->transport('http/429.json'), null, null, $logger);
        $u->resolve(self::PAGE_A);
        $u->resolve('https://example.com/');
        self::assertCount(2, $logger->messages('debug'));
        self::assertSame(['gnews-unwrap: rate_limited'], $logger->messages('warning'));

        $stats = $u->stats();
        self::assertSame(2, $stats['links']);
        self::assertSame(1, $stats['wrappers']);
        self::assertSame(1, $stats['requests']);
        self::assertSame(['rate_limited' => 1, 'not_google_news' => 1], $stats['unresolved']);
    }

    public function testResolveOrSameAndNullCache(): void
    {
        $transport = $this->transport('batch/ok.json');
        $u = $this->unwrapper($transport, null, new NullCache());
        self::assertSame('https://example.com/news/2026/11/11/budget-session-opens', $u->resolveOrSame(self::PAGE_A));
        self::assertSame('https://example.com/x', $u->resolveOrSame('https://example.com/x'));
        $u->resolve(self::PAGE_A);
        self::assertCount(4, $transport->requests(), 'null cache stores nothing');
    }

    public function testCorruptCacheEntryIsIgnored(): void
    {
        $cache = new ArrayCache(10, $this->clock);
        $u = $this->unwrapper($this->transport('batch/ok.json'), null, $cache);
        $u->resolve(self::PAGE_A);
        // Overwrite every entry with junk.
        $ref = new \ReflectionProperty(ArrayCache::class, 'items');
        $items = $ref->getValue($cache);
        self::assertIsArray($items);
        foreach (array_keys($items) as $key) {
            $cache->set((string) $key, ['junk' => true], 60);
        }
        self::assertSame(Method::BatchExecute, $u->resolve(self::PAGE_A)->method);
    }

    public function testTransportContractViolation(): void
    {
        $bad = new BrokenConcurrentTransport();
        $u = new Unwrapper((new Config())->withConcurrency(2), $bad, null, $this->clock);
        $this->expectException(TransportContractException::class);
        $u->resolveMany([self::PAGE_A]);
    }

    /** Concurrent resolveMany(): a failure on one link does not touch the others. */
    public function testResolveManyConcurrentIsolatesErrors(): void
    {
        $transport = new FakeConcurrentTransport($this->clock);
        $transport->load(self::fixture('batch/ok.json'));
        $broken = 'https://news.google.com/rss/articles/CBMiHEFVX3lxTE9wYXF1ZUZpeHR1cmVUb2tlbjAwMDM';
        $transport->on($broken, TransportException::network('connection reset'));
        $u = $this->unwrapper($transport, (new Config())->withConcurrency(4));

        $results = $u->resolveMany([$broken, self::PAGE_A]);
        self::assertTrue($results[self::PAGE_A]->resolved);
        self::assertFalse($results[$broken]->resolved);
        self::assertSame(Reason::NetworkError, $results[$broken]->reason);
        self::assertSame($broken, $results[$broken]->output);
    }

    /** A concurrent transport that throws fails its round with network_error, never escapes. */
    public function testResolveManyConcurrentTransportThrows(): void
    {
        $throwing = new class implements ConcurrentTransport {
            public function send(Request $request): Response
            {
                throw new \RuntimeException('boom');
            }

            public function sendMany(array $requests): array
            {
                throw new \RuntimeException('multi boom');
            }
        };
        $legacy = 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://example.com/offline');
        $u = new Unwrapper((new Config())->withConcurrency(4), $throwing, new ArrayCache(10, $this->clock), $this->clock);
        $results = $u->resolveMany([self::PAGE_A, $legacy, self::WRAPPER_B]);

        self::assertSame(Reason::NetworkError, $results[self::PAGE_A]->reason);
        self::assertSame(Reason::NetworkError, $results[self::WRAPPER_B]->reason);
        self::assertTrue($results[$legacy]->resolved, 'offline links are unaffected');
    }

    /** Generic mode checks every hop, not only the first, and refuses ambiguous authorities. */
    public function testGenericModeChecksEveryHop(): void
    {
        $guard = new AddressGuard(static fn (string $h): array => match ($h) {
            'internal.example' => ['10.0.0.8'],
            'v6.example' => ['2606:4700::1111', '::1'],
            default => ['93.184.215.14'],
        });
        $config = (new Config())->withGenericMode(true);

        $chain = (new FakeTransport($this->clock))
            ->redirect('https://sho.rt/a', 'https://hop.example/b')
            ->redirect('https://hop.example/b', 'https://hop2.example/c')
            ->redirect('https://hop2.example/c', 'https://internal.example/admin');
        $r = $this->unwrapper($chain, $config, null, null, $guard)->resolve('https://sho.rt/a');
        self::assertSame(Reason::PrivateAddress, $r->reason);
        self::assertCount(3, $chain->requests(), 'the private hop itself is never requested');

        $v6 = (new FakeTransport($this->clock))->redirect('https://sho.rt/a', 'https://v6.example/x');
        $r = $this->unwrapper($v6, $config, null, null, $guard)->resolve('https://sho.rt/a');
        self::assertSame(Reason::PrivateAddress, $r->reason, 'an AAAA record on loopback is refused');

        foreach (['https://user@example.com/x', 'https://example.com\\@127.0.0.1/x'] as $hop) {
            $odd = (new FakeTransport($this->clock))->redirect('https://sho.rt/a', $hop);
            $r = $this->unwrapper($odd, $config, null, null, $guard)->resolve('https://sho.rt/a');
            self::assertFalse($r->resolved, $hop);
            self::assertCount(1, $odd->requests(), $hop);
        }
    }

    /** A positive cache entry that today's rules reject is treated as a miss. */
    public function testCachedTargetIsRevalidated(): void
    {
        $cache = new ArrayCache(10, $this->clock);
        $transport = $this->transport('batch/ok.json');
        self::assertSame(Method::BatchExecute, $this->unwrapper($transport, null, $cache)->resolve(self::PAGE_A)->method);
        self::assertSame(Method::Cache, $this->unwrapper($transport, null, $cache)->resolve(self::PAGE_A)->method);

        $own = $this->unwrapper($transport, (new Config())->withOwnHosts(['example.com']), $cache)->resolve(self::PAGE_A);
        self::assertFalse($own->resolved);
        self::assertNotSame(Method::Cache, $own->method);
    }
}
