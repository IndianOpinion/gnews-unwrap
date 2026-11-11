<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Turns a URL or a host into an outlet key: one string per outlet, so
 * www., amp., m. and mobile. variants count once.
 *
 * Pure and offline. It does not resolve wrapper links: unwrap first, then
 * normalise.
 */
final class HostNormaliser
{
    /** @var list<string> */
    public const DEFAULT_STRIP_LABELS = ['www', 'amp', 'm', 'mobile'];

    /**
     * Built-in multi-part public suffixes. Enough to stop the stripper at
     * the registrable domain for the common news markets. Load a full
     * Public Suffix List file for complete coverage.
     *
     * @var list<string>
     */
    public const DEFAULT_SUFFIXES = [
        // United Kingdom
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'sch.uk', 'nhs.uk',
        // India
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'ac.in', 'edu.in', 'res.in', 'gov.in', 'nic.in',
        // Australia and New Zealand
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
        // Africa
        'co.za', 'org.za', 'gov.za', 'ac.za', 'com.ng', 'gov.ng', 'co.ke', 'or.ke', 'com.eg', 'com.gh', 'co.tz', 'co.ug',
        // Americas
        'com.br', 'net.br', 'org.br', 'gov.br', 'com.mx', 'gob.mx', 'com.ar', 'gob.ar', 'com.co', 'com.pe',
        // Asia
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp', 'co.kr', 'or.kr', 'com.cn', 'net.cn', 'org.cn', 'gov.cn',
        'com.hk', 'com.tw', 'com.sg', 'com.my', 'com.ph', 'co.id', 'or.id', 'co.th', 'in.th', 'com.vn',
        'com.pk', 'gov.pk', 'com.bd', 'gov.bd', 'com.np', 'gov.np', 'gov.lk', 'com.lk',
        // Middle East and Europe
        'co.il', 'org.il', 'com.tr', 'gov.tr', 'com.sa', 'gov.sa', 'com.qa', 'co.ae', 'gov.ae', 'com.ua', 'com.pl',
    ];

    /** @var array<string, true> */
    private readonly array $strip;
    /** @var array<string, true> */
    private readonly array $suffixes;
    /** @var array<string, string> exact host => key */
    private readonly array $aliasExact;
    /** @var array<string, string> suffix (without "*.") => key */
    private readonly array $aliasWildcard;
    private readonly bool $useIntl;

    /**
     * @param list<string> $stripLabels whole leading labels to strip, repeatedly
     * @param bool $stripNumberedWww also strip www1, www2, ww2 and the like
     * @param list<string> $extraSuffixes added to the built-in multi-part suffixes
     * @param array<string, string> $aliases host or "*.suffix" => outlet key, applied last
     * @param PublicSuffixList|null $publicSuffixList when given, used instead of the built-in list
     * @param bool|null $useIntl null means "when ext-intl is loaded"; false forces the fallback
     */
    public function __construct(
        array $stripLabels = self::DEFAULT_STRIP_LABELS,
        private readonly bool $stripNumberedWww = true,
        array $extraSuffixes = [],
        array $aliases = [],
        private readonly ?PublicSuffixList $publicSuffixList = null,
        ?bool $useIntl = null,
    ) {
        $strip = [];
        foreach ($stripLabels as $label) {
            $label = Url::lower(trim((string) $label));
            if (preg_match('/^[a-z0-9-]+$/', $label) !== 1) {
                throw new InvalidConfigException('Strip label must be a single host label: ' . $label);
            }
            $strip[$label] = true;
        }
        $this->strip = $strip;

        $suffixes = [];
        foreach (array_merge(self::DEFAULT_SUFFIXES, $extraSuffixes) as $suffix) {
            $suffix = trim(Url::lower((string) $suffix), '.');
            if ($suffix === '' || preg_match('/^[a-z0-9.-]+$/', $suffix) !== 1) {
                throw new InvalidConfigException('Suffix must be an ASCII domain suffix: ' . $suffix);
            }
            $suffixes[$suffix] = true;
        }
        $this->suffixes = $suffixes;

        $exact = [];
        $wild = [];
        foreach ($aliases as $from => $to) {
            $from = Url::normaliseHost(trim((string) $from));
            $to = Url::normaliseHost(trim((string) $to));
            if ($from === '' || $to === '') {
                throw new InvalidConfigException('Outlet aliases need a non-empty host and key.');
            }
            if (str_starts_with($from, '*.')) {
                $wild[substr($from, 2)] = $to;
            } else {
                $exact[$from] = $to;
            }
        }
        $this->aliasExact = $exact;
        $this->aliasWildcard = $wild;

        $intl = function_exists('idn_to_ascii');
        $this->useIntl = $useIntl === null ? $intl : ($useIntl && $intl);
    }

    /** The outlet key, or '' when no host can be found. */
    public function key(string $urlOrHost): string
    {
        return $this->normalise($urlOrHost)->key;
    }

    public function normalise(string $urlOrHost): HostKey
    {
        $host = $this->extractHost(trim($urlOrHost));
        if ($host === null || $host === '') {
            return new HostKey('');
        }

        // IP literals: bracketed IPv6, bare IPv6, IPv4.
        if (str_starts_with($host, '[')) {
            return new HostKey(Url::lower($host));
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return new HostKey(Url::lower($host));
        }

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        $host = Url::lower($host);
        if ($host === '') {
            return new HostKey('');
        }

        $idnSkipped = false;
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if ($this->useIntl) {
                $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($ascii === false || $ascii === '') {
                    return new HostKey('');
                }
                $host = Url::lower($ascii);
            } else {
                $idnSkipped = true;
                if (preg_match('//u', $host) !== 1) {
                    return new HostKey('');
                }
                if (function_exists('mb_strtolower')) {
                    $host = mb_strtolower($host, 'UTF-8');
                }
            }
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === '' || preg_match('/^[\p{L}\p{M}\p{N}_-]+$/u', $label) !== 1) {
                return new HostKey('');
            }
        }

        $floor = $this->suffixLabelCount($labels) + 1;
        while (count($labels) > $floor && $this->isStrippable($labels[0])) {
            array_shift($labels);
        }
        $key = implode('.', $labels);

        return new HostKey($this->alias($key), $idnSkipped);
    }

    /**
     * Labels in the public suffix of the host (at least 1).
     *
     * @param list<string> $labels
     */
    public function suffixLabelCount(array $labels): int
    {
        if ($this->publicSuffixList !== null) {
            return max(1, $this->publicSuffixList->suffixLabelCount($labels));
        }
        $count = count($labels);
        for ($n = min(3, $count - 1); $n >= 2; $n--) {
            if (isset($this->suffixes[implode('.', array_slice($labels, -$n))])) {
                return $n;
            }
        }

        return 1;
    }

    private function isStrippable(string $label): bool
    {
        if (isset($this->strip[$label])) {
            return true;
        }

        return $this->stripNumberedWww && preg_match('/^(?:www\d*|ww\d+)$/', $label) === 1;
    }

    private function alias(string $key): string
    {
        if (isset($this->aliasExact[$key])) {
            return $this->aliasExact[$key];
        }
        foreach ($this->aliasWildcard as $suffix => $to) {
            if (str_ends_with($key, '.' . $suffix)) {
                return $to;
            }
        }

        return $key;
    }

    private function extractHost(string $input): ?string
    {
        if ($input === '' || strlen($input) > Url::MAX_LENGTH || Url::hasControlOrSpace($input)) {
            return null;
        }
        if (str_starts_with($input, '//')) {
            $input = 'http:' . $input;
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $input) === 1) {
            $host = parse_url($input, PHP_URL_HOST);

            return is_string($host) ? $host : null;
        }

        // A bare host, possibly with userinfo, port or a path.
        $host = (string) preg_replace('~[/?#].*$~s', '', $input);
        $at = strrpos($host, '@');
        if ($at !== false) {
            $host = substr($host, $at + 1);
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? null : substr($host, 0, $end + 1);
        }
        if (substr_count($host, ':') === 1) {
            $host = substr($host, 0, (int) strpos($host, ':'));
        }

        return $host;
    }
}
