<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Integration;

use IndianOpinion\GnewsUnwrap\Exception\TransportException;
use IndianOpinion\GnewsUnwrap\Transport\CurlTransport;
use IndianOpinion\GnewsUnwrap\Transport\Request;
use IndianOpinion\GnewsUnwrap\Transport\Response;
use IndianOpinion\GnewsUnwrap\Transport\StreamTransport;
use IndianOpinion\GnewsUnwrap\Transport\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shipped transports against PHP's built-in server on 127.0.0.1.
 * No outside network is used.
 */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = random_int(20000, 40000);
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server/router.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('Cannot start the PHP built-in server.');
        }
        self::$process = $process;
        self::$base = 'http://127.0.0.1:' . $port;

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);

                    return;
                }
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        self::markTestSkipped('The PHP built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    /** @return iterable<string, array{\Closure(): Transport}> */
    public static function transports(): iterable
    {
        yield 'curl' => [static fn (): Transport => new CurlTransport()];
        yield 'streams' => [static fn (): Transport => new StreamTransport()];
    }

    private function get(Transport $t, string $path, float $timeout = 5.0, int $max = 524_288): Response
    {
        return $t->send(new Request('GET', self::$base . $path, ['User-Agent' => 'gnews-unwrap-test'], null, 2.0, $timeout, $max));
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testOk(\Closure $make): void
    {
        $r = $this->get($make(), '/ok');
        self::assertSame(200, $r->status);
        self::assertSame('<html>ok</html>', $r->body);
        self::assertSame('text/html', $r->mediaType());
        self::assertSame('one', $r->header('X-Test'));
        self::assertFalse($r->truncated);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testDoesNotFollowRedirects(\Closure $make): void
    {
        $r = $this->get($make(), '/redirect');
        self::assertSame(302, $r->status);
        self::assertSame('/ok', $r->header('location'));
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testBodyCap(\Closure $make): void
    {
        $r = $this->get($make(), '/big', 5.0, 10_000);
        self::assertSame(200, $r->status);
        self::assertSame(10_000, strlen($r->body));
        self::assertTrue($r->truncated);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testStatusIsReturned(\Closure $make): void
    {
        $r = $this->get($make(), '/missing');
        self::assertSame(404, $r->status);
        self::assertSame('nope', $r->body);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testPost(\Closure $make): void
    {
        $r = $make()->send(new Request('POST', self::$base . '/echo', ['Content-Type' => 'application/x-www-form-urlencoded'], 'f.req=abc'));
        $data = json_decode($r->body, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('POST', $data['method']);
        self::assertSame('f.req=abc', $data['body']);
        self::assertSame('application/x-www-form-urlencoded', $data['type']);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testNoCookiesKept(\Closure $make): void
    {
        $t = $make();
        $this->get($t, '/set-cookie');
        $data = json_decode($this->get($t, '/echo')->body, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNull($data['cookie']);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testTimeout(\Closure $make): void
    {
        $this->expectException(TransportException::class);
        try {
            $this->get($make(), '/slow', 0.5);
        } catch (TransportException $e) {
            self::assertTrue($e->isTimeout(), $e->getMessage());
            throw $e;
        }
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testConnectionRefused(\Closure $make): void
    {
        $this->expectException(TransportException::class);
        $make()->send(new Request('GET', 'http://127.0.0.1:1/', [], null, 1.0, 1.0));
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testRefusesOtherSchemes(\Closure $make): void
    {
        $this->expectException(TransportException::class);
        $make()->send(new Request('GET', 'file:///etc/hostname'));
    }

    public function testCurlSendMany(): void
    {
        $t = new CurlTransport([], 4);
        $out = $t->sendMany([
            'a' => new Request('GET', self::$base . '/ok'),
            'b' => new Request('GET', self::$base . '/redirect'),
            'c' => new Request('GET', 'http://127.0.0.1:1/', [], null, 1.0, 1.0),
            'd' => new Request('GET', 'ftp://example.com/'),
        ]);
        self::assertSame(['a', 'b', 'c', 'd'], array_keys(array_merge(['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0], $out)));
        self::assertInstanceOf(Response::class, $out['a']);
        self::assertSame(200, $out['a']->status);
        self::assertInstanceOf(Response::class, $out['b']);
        self::assertSame(302, $out['b']->status);
        self::assertInstanceOf(TransportException::class, $out['c']);
        self::assertInstanceOf(TransportException::class, $out['d']);
    }
}
