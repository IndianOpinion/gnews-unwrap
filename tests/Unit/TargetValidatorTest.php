<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Unit;

use IndianOpinion\GnewsUnwrap\AddressGuard;
use IndianOpinion\GnewsUnwrap\GoogleHosts;
use IndianOpinion\GnewsUnwrap\HostNormaliser;
use IndianOpinion\GnewsUnwrap\TargetValidator;
use IndianOpinion\GnewsUnwrap\Tests\Support\TestCase;

final class TargetValidatorTest extends TestCase
{
    /** T42: Google hosts, own hosts, IPs, private addresses and the wrapper itself are rejected. */
    public function testRejections(): void
    {
        $v = new TargetValidator(new GoogleHosts(['gnews-mirror.test']), new HostNormaliser(), ['https://www.mysite.example/']);
        $rejected = [
            'https://news.google.com/home',
            'https://www.google.co.in/search?q=x',
            'https://consent.google.com/ml',
            'https://accounts.google.com/',
            'https://fonts.gstatic.com/x.woff',
            'https://consent.youtube.com/m',
            'https://sub.gnews-mirror.test/a',
            'https://mysite.example/story',
            'https://m.mysite.example/story',
            'https://blog.mysite.example/story',
            'http://93.184.215.14/a',
            'http://[2001:db8::1]/a',
            'http://10.0.0.5/a',
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost/a',
            'http://api.localhost/a',
            'http://2130706433/',
            'http://0x7f000001/',
            'ftp://example.com/a',
            'mailto:a@example.com',
            '/relative/path',
            self::WRAPPER_B,
        ];
        foreach ($rejected as $url) {
            self::assertNull($v->accept($url, self::WRAPPER_B), $url);
        }
        self::assertSame('https://example.com/a', $v->accept('  https://example.com/a  '));
        self::assertSame('https://www.youtube.com/watch?v=1', $v->accept('https://www.youtube.com/watch?v=1'));
        self::assertSame('https://notgoogle.com/a', $v->accept('https://notgoogle.com/a'));
    }

    public function testAllowPrivate(): void
    {
        $v = new TargetValidator(allowPrivate: true);
        self::assertSame('http://10.0.0.5/a', $v->accept('http://10.0.0.5/a'));
        self::assertSame('http://localhost:8080/a', $v->accept('http://localhost:8080/a'));
    }

    /** T43: length and control characters. */
    public function testLengthAndControlCharacters(): void
    {
        $v = new TargetValidator();
        $long = 'https://example.com/' . str_repeat('a', 2048);
        self::assertNull($v->accept($long));
        self::assertNotNull($v->accept(substr($long, 0, 2048)));
        self::assertNull($v->accept("https://example.com/a\x00b"));
        self::assertNull($v->accept("https://example.com/a\nb"));
        self::assertNull($v->accept('https://example.com/a b'));
        self::assertNull($v->accept("https://example.com/\x7f"));
        self::assertNull($v->accept('https://exa<mple.com/'));
    }

    public function testAddressGuard(): void
    {
        $guard = new AddressGuard(static fn (string $h): array => $h === 'internal.example' ? ['10.1.2.3'] : ['93.184.215.14']);
        $blocked = [
            'localhost', '127.0.0.1', '10.0.0.1', '192.168.1.1', '172.16.0.1', '169.254.169.254', '100.64.0.1',
            '0.0.0.0', '[::1]', '[fe80::1]', '[fd00::1]', '[::ffff:127.0.0.1]', '2130706433', 'internal.example',
        ];
        foreach ($blocked as $host) {
            self::assertTrue($guard->isBlocked($host), $host);
        }
        self::assertFalse($guard->isBlocked('example.com'));
        self::assertFalse($guard->isBlocked('93.184.215.14'));
    }
}
