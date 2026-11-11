<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * SSRF checks for generic redirect mode: refuse loopback, private,
 * link-local, carrier-grade NAT, reserved and metadata addresses, both as
 * literals and after DNS resolution.
 */
final class AddressGuard
{
    /** @var \Closure(string): list<string> */
    private readonly \Closure $lookup;

    /**
     * @param (\Closure(string): list<string>)|null $lookup host => IP addresses; default uses gethostbynamel()
     */
    public function __construct(?\Closure $lookup = null)
    {
        $this->lookup = $lookup ?? static function (string $host): array {
            $ips = gethostbynamel($host);

            return $ips === false ? [] : $ips;
        };
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
            // Decimal, octal or hex forms such as 2130706433 or 0x7f000001.
            return true;
        }
        foreach (($this->lookup)($host) as $ip) {
            if (self::isBlockedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        $lower = Url::lower($ip);
        // Carrier-grade NAT 100.64.0.0/10.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (100 << 24 | 64 << 16)) {
                return true;
            }

            return false;
        }
        // IPv4-mapped IPv6, unique local and link-local, in case the filter flags miss them.
        if (str_starts_with($lower, '::ffff:')) {
            return self::isBlockedIp(substr($lower, 7));
        }

        return str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe80');
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
}
