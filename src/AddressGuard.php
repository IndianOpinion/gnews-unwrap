<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * SSRF checks for generic redirect mode: refuse loopback, private,
 * link-local, carrier-grade NAT, reserved, multicast, documentation and
 * metadata addresses, both as literals and after DNS resolution (A and
 * AAAA records). IPv6 forms that embed an IPv4 address (mapped, NAT64,
 * 6to4) are checked on the embedded address.
 *
 * Limitation: the transport resolves the host again when it connects, so a
 * hostile DNS server can answer differently the second time (DNS
 * rebinding). Run generic mode on untrusted input only behind an egress
 * firewall.
 */
final class AddressGuard
{
    /** IPv4 ranges refused, as [network, prefix length]. */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],        // "this network"
        ['10.0.0.0', 8],       // private
        ['100.64.0.0', 10],    // carrier-grade NAT
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local, cloud metadata
        ['172.16.0.0', 12],    // private
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // documentation
        ['192.88.99.0', 24],   // 6to4 relay anycast
        ['192.168.0.0', 16],   // private
        ['198.18.0.0', 15],    // benchmarking
        ['198.51.100.0', 24],  // documentation
        ['203.0.113.0', 24],   // documentation
        ['224.0.0.0', 4],      // multicast
        ['240.0.0.0', 4],      // reserved and broadcast
    ];

    /** IPv6 ranges refused outright, as [network, prefix length]. */
    private const BLOCKED_V6 = [
        ['::', 96],            // unspecified, loopback, IPv4-compatible
        ['64:ff9b:1::', 48],   // local-use NAT64
        ['100::', 64],         // discard
        ['2001::', 32],        // Teredo
        ['2001:db8::', 32],    // documentation
        ['fc00::', 7],         // unique local
        ['fe80::', 10],        // link-local
        ['fec0::', 10],        // site-local (deprecated)
        ['ff00::', 8],         // multicast
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $lookup;

    /**
     * @param (\Closure(string): list<string>)|null $lookup host => IP addresses; default reads A and AAAA records
     */
    public function __construct(?\Closure $lookup = null)
    {
        $this->lookup = $lookup ?? static fn (string $host): array => self::systemLookup($host);
    }

    /**
     * True when a request to this host must be refused. A host that does
     * not resolve is not blocked here: the transport will fail on it.
     */
    public function isBlocked(string $host): bool
    {
        $host = Url::normaliseHost($host);
        if ($host === '' || self::isLocalName($host)) {
            return true;
        }
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return self::isBlockedIp($bare);
        }
        if (self::isIpLike($host)) {
            // Decimal, octal or hex forms such as 2130706433 or 0x7f000001,
            // and bracketed forms that are not plain IPv6 (zone ids).
            return true;
        }
        foreach (($this->lookup)($host) as $ip) {
            if (self::isBlockedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /** True for any address in a refused range, and for anything that is not an IP address. */
    public static function isBlockedIp(string $ip): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 4) {
            return self::inRanges($packed, self::BLOCKED_V4);
        }

        // IPv6 forms that carry an IPv4 address: check the embedded one.
        if (self::inRange($packed, '::ffff:0:0', 96) || self::inRange($packed, '64:ff9b::', 96)) {
            return self::inRanges(substr($packed, 12, 4), self::BLOCKED_V4);
        }
        if (self::inRange($packed, '2002::', 16)) {
            return self::inRanges(substr($packed, 2, 4), self::BLOCKED_V4);
        }

        return self::inRanges($packed, self::BLOCKED_V6);
    }

    /** IP literal in any form, or an all-numeric or hex host that resolvers treat as one. */
    public static function isIpLike(string $host): bool
    {
        $host = Url::normaliseHost($host);
        if (str_starts_with($host, '[')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/', $host) === 1;
    }

    public static function isLocalName(string $host): bool
    {
        $host = Url::normaliseHost($host);

        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    /**
     * A and AAAA addresses of a host. Lookup failures give an empty list.
     *
     * @return list<string>
     */
    private static function systemLookup(string $host): array
    {
        $ips = gethostbynamel($host);
        $ips = $ips === false ? [] : $ips;
        if (function_exists('dns_get_record')) {
            set_error_handler(static fn (): bool => true);
            try {
                $records = dns_get_record($host, DNS_AAAA);
            } finally {
                restore_error_handler();
            }
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_string($record['ipv6'] ?? null)) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /** @param list<array{0: string, 1: int}> $ranges */
    private static function inRanges(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $bits]) {
            if (self::inRange($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $network, int $bits): bool
    {
        $net = inet_pton($network);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
