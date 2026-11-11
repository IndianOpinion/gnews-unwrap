<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;

/**
 * Immutable settings. Every value is checked in the constructor and an
 * InvalidConfigException names the bad one. Change a setting with the
 * matching with...() copier; the original is left as it was.
 */
final class Config
{
    /** @var list<Method> */
    public readonly array $methods;
    /** @var list<string> */
    public readonly array $ownHosts;
    /** @var list<string> */
    public readonly array $extraGoogleHosts;
    /** @var list<string> */
    public readonly array $stripLabels;
    /** @var list<string> */
    public readonly array $extraSuffixes;
    /** @var array<string, string> */
    public readonly array $outletAliases;
    /** @var list<string> */
    public readonly array $trackingParams;
    /** @var list<string>|null */
    public readonly ?array $allowParams;
    /** @var array<string, list<string>> */
    public readonly array $identityParams;

    /**
     * @param list<Method>|null $methods enabled methods; null means all six
     * @param bool $webRedirectorScope treat Google web redirector links (S7) as wrappers
     * @param bool $genericMode follow HTTP redirects for any URL, not only Google wrappers
     * @param int $maxRedirects hops per resolution, 0 to 10
     * @param float $connectTimeout seconds
     * @param float $requestTimeout seconds per request
     * @param float $budget seconds per link, across all methods and hops
     * @param int $maxBodyBytes bytes of body read per response
     * @param int $positiveTtl seconds a resolved result is cached
     * @param int $negativeTtl seconds an unresolved result is cached (transient reasons never are)
     * @param int $breakerThreshold rate_limited results that open the circuit breaker
     * @param int $breakerWindow seconds in which those results must fall
     * @param int $breakerCooldown seconds the breaker stays open
     * @param int $pauseMs pause between network resolutions (and between concurrent rounds)
     * @param int $concurrency links resolved at once by resolveMany() on a concurrent transport; 1 (the default) is sequential
     * @param list<string> $ownHosts hosts that are never a valid target (your own sites)
     * @param list<string> $extraGoogleHosts added to the built-in Google host list
     * @param bool $allowPrivateTargets accept IP literal, localhost and private targets
     * @param list<string> $stripLabels leading host labels stripped for the outlet key
     * @param bool $stripNumberedWww also strip www1, www2, ww2 and the like
     * @param list<string> $extraSuffixes multi-part public suffixes added to the built-in list
     * @param string|null $publicSuffixListFile path to a Public Suffix List file; replaces the built-in list
     * @param array<string, string> $outletAliases host or "*.suffix" => outlet key
     * @param list<string>|null $trackingParams query names dropped from the article key; null means the default list
     * @param list<string>|null $allowParams keep only these query names in the article key
     * @param bool $stripAmpMarkers remove AMP markers from the article key (off: some outlets serve other articles there)
     * @param array<string, list<string>> $identityParams host => query names that identify the article
     */
    public function __construct(
        ?array $methods = null,
        public readonly bool $webRedirectorScope = false,
        public readonly bool $genericMode = false,
        public readonly int $maxRedirects = 5,
        public readonly float $connectTimeout = 3.0,
        public readonly float $requestTimeout = 8.0,
        public readonly float $budget = 15.0,
        public readonly int $maxBodyBytes = 524_288,
        public readonly string $userAgent = '',
        public readonly string $acceptLanguage = 'en',
        public readonly int $positiveTtl = 2_592_000,
        public readonly int $negativeTtl = 3_600,
        public readonly int $breakerThreshold = 3,
        public readonly int $breakerWindow = 60,
        public readonly int $breakerCooldown = 300,
        public readonly int $pauseMs = 0,
        public readonly int $concurrency = 1,
        array $ownHosts = [],
        array $extraGoogleHosts = [],
        public readonly bool $allowPrivateTargets = false,
        ?array $stripLabels = null,
        public readonly bool $stripNumberedWww = true,
        array $extraSuffixes = [],
        public readonly ?string $publicSuffixListFile = null,
        array $outletAliases = [],
        ?array $trackingParams = null,
        ?array $allowParams = null,
        public readonly bool $stripAmpMarkers = false,
        array $identityParams = [],
    ) {
        $methods ??= Method::switchable();
        $seen = [];
        foreach ($methods as $method) {
            if (!$method instanceof Method || $method === Method::None) {
                throw new InvalidConfigException('methods must be a list of Method cases other than None.');
            }
            $seen[$method->value] = $method;
        }
        $this->methods = array_values($seen);

        self::range('maxRedirects', $maxRedirects, 0, 10);
        self::rangeFloat('connectTimeout', $connectTimeout, 0.1, 120.0);
        self::rangeFloat('requestTimeout', $requestTimeout, 0.1, 300.0);
        self::rangeFloat('budget', $budget, 0.1, 600.0);
        self::range('maxBodyBytes', $maxBodyBytes, 1024, 16_777_216);
        self::range('positiveTtl', $positiveTtl, 1, 315_360_000);
        self::range('negativeTtl', $negativeTtl, 1, 315_360_000);
        self::range('breakerThreshold', $breakerThreshold, 1, 1000);
        self::range('breakerWindow', $breakerWindow, 1, 86_400);
        self::range('breakerCooldown', $breakerCooldown, 1, 86_400);
        self::range('pauseMs', $pauseMs, 0, 60_000);
        self::range('concurrency', $concurrency, 1, 32);
        if ($userAgent !== '' && preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1) {
            throw new InvalidConfigException('userAgent must not contain control characters.');
        }
        if ($acceptLanguage === '' || preg_match('/[\x00-\x1F\x7F]/', $acceptLanguage) === 1) {
            throw new InvalidConfigException('acceptLanguage must be a non-empty header value.');
        }
        if ($publicSuffixListFile !== null && !is_readable($publicSuffixListFile)) {
            throw new InvalidConfigException('publicSuffixListFile is not readable: ' . $publicSuffixListFile);
        }

        $this->ownHosts = self::strings('ownHosts', $ownHosts);
        $this->extraGoogleHosts = self::strings('extraGoogleHosts', $extraGoogleHosts);
        $this->stripLabels = self::strings('stripLabels', $stripLabels ?? HostNormaliser::DEFAULT_STRIP_LABELS);
        $this->extraSuffixes = self::strings('extraSuffixes', $extraSuffixes);
        $this->trackingParams = self::strings('trackingParams', $trackingParams ?? ArticleKeyBuilder::DEFAULT_TRACKING_PARAMS);
        $this->allowParams = $allowParams === null ? null : self::strings('allowParams', $allowParams);

        $aliases = [];
        foreach ($outletAliases as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                throw new InvalidConfigException('outletAliases must map non-empty host strings to non-empty keys.');
            }
            $aliases[$from] = $to;
        }
        $this->outletAliases = $aliases;

        $identity = [];
        foreach ($identityParams as $host => $names) {
            if (!is_string($host) || $host === '' || !is_array($names)) {
                throw new InvalidConfigException('identityParams must map a host to a list of parameter names.');
            }
            $identity[$host] = self::strings('identityParams', $names);
        }
        $this->identityParams = $identity;

        // Validate the derived objects now, so a bad label fails at construction.
        $this->articleKeyBuilder();
    }

    public function userAgentHeader(): string
    {
        return $this->userAgent === '' ? Version::userAgent() : $this->userAgent;
    }

    public function isEnabled(Method $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function hostNormaliser(): HostNormaliser
    {
        return new HostNormaliser(
            $this->stripLabels,
            $this->stripNumberedWww,
            $this->extraSuffixes,
            $this->outletAliases,
            $this->publicSuffixListFile === null ? null : PublicSuffixList::fromFile($this->publicSuffixListFile),
        );
    }

    public function articleKeyBuilder(?HostNormaliser $hosts = null): ArticleKeyBuilder
    {
        return new ArticleKeyBuilder(
            $hosts ?? $this->hostNormaliser(),
            $this->trackingParams,
            $this->allowParams,
            $this->stripAmpMarkers,
            $this->identityParams,
        );
    }

    /** @return array<string, mixed> constructor arguments, for the copiers */
    private function args(): array
    {
        return [
            'methods' => $this->methods,
            'webRedirectorScope' => $this->webRedirectorScope,
            'genericMode' => $this->genericMode,
            'maxRedirects' => $this->maxRedirects,
            'connectTimeout' => $this->connectTimeout,
            'requestTimeout' => $this->requestTimeout,
            'budget' => $this->budget,
            'maxBodyBytes' => $this->maxBodyBytes,
            'userAgent' => $this->userAgent,
            'acceptLanguage' => $this->acceptLanguage,
            'positiveTtl' => $this->positiveTtl,
            'negativeTtl' => $this->negativeTtl,
            'breakerThreshold' => $this->breakerThreshold,
            'breakerWindow' => $this->breakerWindow,
            'breakerCooldown' => $this->breakerCooldown,
            'pauseMs' => $this->pauseMs,
            'concurrency' => $this->concurrency,
            'ownHosts' => $this->ownHosts,
            'extraGoogleHosts' => $this->extraGoogleHosts,
            'allowPrivateTargets' => $this->allowPrivateTargets,
            'stripLabels' => $this->stripLabels,
            'stripNumberedWww' => $this->stripNumberedWww,
            'extraSuffixes' => $this->extraSuffixes,
            'publicSuffixListFile' => $this->publicSuffixListFile,
            'outletAliases' => $this->outletAliases,
            'trackingParams' => $this->trackingParams,
            'allowParams' => $this->allowParams,
            'stripAmpMarkers' => $this->stripAmpMarkers,
            'identityParams' => $this->identityParams,
        ];
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...array_merge($this->args(), $changes));
    }

    /** @param list<Method> $methods */
    public function withMethods(array $methods): self
    {
        return $this->with(['methods' => $methods]);
    }

    public function withoutMethods(Method ...$methods): self
    {
        return $this->with(['methods' => array_values(array_filter(
            $this->methods,
            static fn (Method $m): bool => !in_array($m, $methods, true)
        ))]);
    }

    public function withWebRedirectorScope(bool $on): self
    {
        return $this->with(['webRedirectorScope' => $on]);
    }

    public function withGenericMode(bool $on): self
    {
        return $this->with(['genericMode' => $on]);
    }

    public function withMaxRedirects(int $hops): self
    {
        return $this->with(['maxRedirects' => $hops]);
    }

    public function withConnectTimeout(float $seconds): self
    {
        return $this->with(['connectTimeout' => $seconds]);
    }

    public function withRequestTimeout(float $seconds): self
    {
        return $this->with(['requestTimeout' => $seconds]);
    }

    public function withBudget(float $seconds): self
    {
        return $this->with(['budget' => $seconds]);
    }

    public function withMaxBodyBytes(int $bytes): self
    {
        return $this->with(['maxBodyBytes' => $bytes]);
    }

    public function withUserAgent(string $userAgent): self
    {
        return $this->with(['userAgent' => $userAgent]);
    }

    public function withAcceptLanguage(string $language): self
    {
        return $this->with(['acceptLanguage' => $language]);
    }

    public function withCacheLifetimes(int $positiveTtl, int $negativeTtl): self
    {
        return $this->with(['positiveTtl' => $positiveTtl, 'negativeTtl' => $negativeTtl]);
    }

    public function withCircuitBreaker(int $threshold, int $windowSeconds, int $cooldownSeconds): self
    {
        return $this->with([
            'breakerThreshold' => $threshold,
            'breakerWindow' => $windowSeconds,
            'breakerCooldown' => $cooldownSeconds,
        ]);
    }

    public function withPauseMs(int $milliseconds): self
    {
        return $this->with(['pauseMs' => $milliseconds]);
    }

    public function withConcurrency(int $links): self
    {
        return $this->with(['concurrency' => $links]);
    }

    /** @param list<string> $hosts */
    public function withOwnHosts(array $hosts): self
    {
        return $this->with(['ownHosts' => $hosts]);
    }

    /** @param list<string> $hosts */
    public function withExtraGoogleHosts(array $hosts): self
    {
        return $this->with(['extraGoogleHosts' => $hosts]);
    }

    public function withAllowPrivateTargets(bool $allow): self
    {
        return $this->with(['allowPrivateTargets' => $allow]);
    }

    /** @param list<string> $labels */
    public function withStripLabels(array $labels, bool $stripNumberedWww = true): self
    {
        return $this->with(['stripLabels' => $labels, 'stripNumberedWww' => $stripNumberedWww]);
    }

    /** @param list<string> $suffixes */
    public function withExtraSuffixes(array $suffixes): self
    {
        return $this->with(['extraSuffixes' => $suffixes]);
    }

    public function withPublicSuffixListFile(?string $path): self
    {
        return $this->with(['publicSuffixListFile' => $path]);
    }

    /** @param array<string, string> $aliases */
    public function withOutletAliases(array $aliases): self
    {
        return $this->with(['outletAliases' => $aliases]);
    }

    /** @param list<string> $params */
    public function withTrackingParams(array $params): self
    {
        return $this->with(['trackingParams' => $params]);
    }

    /** @param list<string> $params */
    public function withExtraTrackingParams(array $params): self
    {
        return $this->with(['trackingParams' => array_values(array_unique(array_merge($this->trackingParams, $params)))]);
    }

    /** @param list<string>|null $params */
    public function withAllowParams(?array $params): self
    {
        return $this->with(['allowParams' => $params]);
    }

    public function withStripAmpMarkers(bool $strip): self
    {
        return $this->with(['stripAmpMarkers' => $strip]);
    }

    /** @param array<string, list<string>> $params */
    public function withIdentityParams(array $params): self
    {
        return $this->with(['identityParams' => $params]);
    }

    private static function range(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidConfigException(sprintf('%s must be between %d and %d, got %d.', $name, $min, $max, $value));
        }
    }

    private static function rangeFloat(string $name, float $value, float $min, float $max): void
    {
        if (is_nan($value) || $value < $min || $value > $max) {
            throw new InvalidConfigException(sprintf('%s must be between %s and %s seconds.', $name, $min, $max));
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function strings(string $name, array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidConfigException($name . ' must be a list of non-empty strings.');
            }
            $out[] = trim($value);
        }

        return $out;
    }
}
