<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\Cache\ArrayCache;
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Exception\GnewsUnwrapException;
use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Transport\CurlTransport;
use IndianOpinion\GnewsUnwrap\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $c = new Config();
        self::assertSame(Method::switchable(), $c->methods);
        self::assertFalse($c->webRedirectorScope);
        self::assertFalse($c->genericMode);
        self::assertSame(5, $c->maxRedirects);
        self::assertSame(3.0, $c->connectTimeout);
        self::assertSame(8.0, $c->requestTimeout);
        self::assertSame(15.0, $c->budget);
        self::assertSame(524_288, $c->maxBodyBytes);
        self::assertSame(2_592_000, $c->positiveTtl);
        self::assertSame(3_600, $c->negativeTtl);
        self::assertSame([3, 60, 300], [$c->breakerThreshold, $c->breakerWindow, $c->breakerCooldown]);
        self::assertSame(0, $c->pauseMs);
        self::assertTrue($c->stripAmpMarkers);
        self::assertSame('en', $c->acceptLanguage);
        self::assertSame('gnews-unwrap/' . Version::VERSION . ' (+https://github.com/IndianOpinion/gnews-unwrap)', $c->userAgentHeader());
    }

    public function testCopiersLeaveOriginalAlone(): void
    {
        $a = new Config();
        $b = $a->withMaxRedirects(2)->withoutMethods(Method::BatchExecute)->withUserAgent('MyBot/1.0');
        self::assertSame(5, $a->maxRedirects);
        self::assertSame(2, $b->maxRedirects);
        self::assertFalse($b->isEnabled(Method::BatchExecute));
        self::assertTrue($a->isEnabled(Method::BatchExecute));
        self::assertSame('MyBot/1.0', $b->userAgentHeader());
        self::assertContains('newsletter_id', $a->withExtraTrackingParams(['newsletter_id'])->trackingParams);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function badValues(): iterable
    {
        yield 'redirects high' => [static fn () => new Config(maxRedirects: 11)];
        yield 'redirects negative' => [static fn () => new Config(maxRedirects: -1)];
        yield 'timeout zero' => [static fn () => new Config(connectTimeout: 0.0)];
        yield 'timeout nan' => [static fn () => new Config(requestTimeout: NAN)];
        yield 'budget' => [static fn () => new Config(budget: -1.0)];
        yield 'body bytes' => [static fn () => new Config(maxBodyBytes: 10)];
        yield 'ttl' => [static fn () => new Config(negativeTtl: 0)];
        yield 'pause' => [static fn () => new Config(pauseMs: -5)];
        yield 'concurrency' => [static fn () => new Config(concurrency: 0)];
        yield 'user agent crlf' => [static fn () => new Config(userAgent: "x\r\nInjected: 1")];
        yield 'accept language empty' => [static fn () => new Config(acceptLanguage: '')];
        yield 'method none' => [static fn () => new Config(methods: [Method::None])];
        yield 'method string' => [static fn () => new Config(...self::args(['methods' => ['cache']]))];
        yield 'own hosts' => [static fn () => new Config(ownHosts: [''])];
        yield 'strip label' => [static fn () => new Config(stripLabels: ['a.b'])];
        yield 'suffix' => [static fn () => new Config(extraSuffixes: ['bad suffix'])];
        yield 'psl file' => [static fn () => new Config(publicSuffixListFile: '/nonexistent/psl.dat')];
        yield 'alias' => [static fn () => new Config(outletAliases: ['a.com' => ''])];
        yield 'identity' => [static fn () => new Config(...self::args(['identityParams' => ['a.com' => 'id']]))];
        yield 'tracking star' => [static fn () => new Config(trackingParams: ['*'])];
        yield 'cache size' => [static fn () => new ArrayCache(0)];
        if (extension_loaded('curl')) {
            yield 'curl option' => [static fn () => new CurlTransport([CURLOPT_SSL_VERIFYPEER => false])];
            yield 'curl follow' => [static fn () => new CurlTransport([CURLOPT_FOLLOWLOCATION => true])];
        } else {
            yield 'curl missing' => [static fn () => new CurlTransport()];
        }
    }

    /**
     * Wrong-typed arguments, as a caller without static analysis might pass them.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function args(array $args): array
    {
        return $args;
    }

    /** T69: bad values throw a typed exception. */
    #[DataProvider('badValues')]
    public function testValidation(\Closure $build): void
    {
        try {
            $build();
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertInstanceOf(GnewsUnwrapException::class, $e);
        }
    }
}
