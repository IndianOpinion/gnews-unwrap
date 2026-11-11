<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Support;

use IndianOpinion\GnewsUnwrap\AddressGuard;
use IndianOpinion\GnewsUnwrap\Cache\ArrayCache;
use IndianOpinion\GnewsUnwrap\Cache\Cache;
use IndianOpinion\GnewsUnwrap\Clock\FakeClock;
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Log\Logger;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;
use IndianOpinion\GnewsUnwrap\Unwrapper;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Opaque id used by the batch fixtures. */
    protected const ID_A = 'CBMiHEFVX3lxTE9wYXF1ZUZpeHR1cmVUb2tlbjAwMDE';
    /** Opaque id used by the http fixtures. */
    protected const ID_B = 'CBMiHEFVX3lxTE9wYXF1ZUZpeHR1cmVUb2tlbjAwMDI';
    protected const PAGE_A = 'https://news.google.com/rss/articles/' . self::ID_A;
    protected const WRAPPER_B = 'https://news.google.com/rss/articles/' . self::ID_B;
    protected const ENDPOINT = 'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je';

    protected FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    protected static function fixture(string $path): string
    {
        return dirname(__DIR__) . '/fixtures/' . $path;
    }

    protected function transport(string ...$fixtures): FakeTransport
    {
        $transport = new FakeTransport($this->clock);
        foreach ($fixtures as $fixture) {
            $transport->load(self::fixture($fixture));
        }

        return $transport;
    }

    protected function unwrapper(
        FakeTransport $transport,
        ?Config $config = null,
        ?Cache $cache = null,
        ?Logger $logger = null,
        ?AddressGuard $guard = null,
    ): Unwrapper {
        return new Unwrapper(
            $config ?? new Config(),
            $transport,
            $cache ?? new ArrayCache(100, $this->clock),
            $this->clock,
            $logger,
            $guard ?? new AddressGuard(static fn (string $host): array => ['93.184.215.14']),
        );
    }

    /** @return array<string, list<string>> */
    protected static function urlList(): array
    {
        $json = (string) file_get_contents(self::fixture('urls-detect.json'));
        /** @var array<string, list<string>> $data */
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        unset($data['note']);

        return $data;
    }
}
