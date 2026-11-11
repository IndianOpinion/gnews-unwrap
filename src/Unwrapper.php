<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Cache\ArrayCache;
use IndianOpinion\GnewsUnwrap\Cache\Cache;
use IndianOpinion\GnewsUnwrap\Clock\Clock;
use IndianOpinion\GnewsUnwrap\Clock\SystemClock;
use IndianOpinion\GnewsUnwrap\Exception\TransportContractException;
use IndianOpinion\GnewsUnwrap\Exception\TransportException;
use IndianOpinion\GnewsUnwrap\Internal\LinkRun;
use IndianOpinion\GnewsUnwrap\Internal\Outcome;
use IndianOpinion\GnewsUnwrap\Internal\Url;
use IndianOpinion\GnewsUnwrap\Log\Logger;
use IndianOpinion\GnewsUnwrap\Transport\ConcurrentTransport;
use IndianOpinion\GnewsUnwrap\Transport\CurlTransport;
use IndianOpinion\GnewsUnwrap\Transport\Request;
use IndianOpinion\GnewsUnwrap\Transport\Response;
use IndianOpinion\GnewsUnwrap\Transport\StreamTransport;
use IndianOpinion\GnewsUnwrap\Transport\Transport;

/**
 * Resolves Google News wrapper links to publisher URLs.
 *
 * Fail open on the item, closed on the target: an unresolved link comes
 * back unchanged with a reason, and a doubtful target is never returned as
 * resolved. Bad input never throws.
 *
 * Each link runs as a small state machine (a generator) that yields HTTP
 * requests and receives responses. resolve() drives one machine with the
 * transport; resolveMany() drives several at once through curl_multi when
 * the transport supports it.
 */
final class Unwrapper
{
    private const CACHE_PREFIX = 'gnews-unwrap:v1:';
    private const MAX_NESTING = 3;
    private const GENERIC_BODY_BYTES = 1024;
    private const LEAKED_GOOGLE_PARAMS = ['ct', 'cd', 'usg', 'ved', 'sa'];
    private const BAD_SCHEMES = '/^\s*(?:javascript|data|file|intent|vbscript|about|blob|ftp|mailto|tel|chrome|market)\s*:/i';

    private readonly Config $config;
    private readonly Transport $transport;
    private readonly Cache $cache;
    private readonly Clock $clock;
    private readonly ?Logger $logger;
    private readonly Detector $detector;
    private readonly Detector $wideDetector;
    private readonly GoogleHosts $google;
    private readonly HostNormaliser $hosts;
    private readonly ArticleKeyBuilder $articleKeys;
    private readonly TargetValidator $validator;
    private readonly LegacyIdDecoder $decoder;
    private readonly HtmlTargetExtractor $html;
    private readonly AddressGuard $guard;
    private readonly CircuitBreaker $breaker;
    private bool $networkUsed = false;

    /** @var array{links: int, wrappers: int, requests: int, cache_hits: int, resolved: array<string, int>, unresolved: array<string, int>} */
    private array $stats = ['links' => 0, 'wrappers' => 0, 'requests' => 0, 'cache_hits' => 0, 'resolved' => [], 'unresolved' => []];

    public function __construct(
        ?Config $config = null,
        ?Transport $transport = null,
        ?Cache $cache = null,
        ?Clock $clock = null,
        ?Logger $logger = null,
        ?AddressGuard $addressGuard = null,
    ) {
        $this->config = $config ?? new Config();
        $this->clock = $clock ?? new SystemClock();
        $this->transport = $transport ?? self::defaultTransport();
        $this->cache = $cache ?? new ArrayCache(10_000, $this->clock);
        $this->logger = $logger;
        $this->detector = new Detector($this->config->webRedirectorScope);
        $this->wideDetector = new Detector(true);
        $this->google = new GoogleHosts($this->config->extraGoogleHosts);
        $this->hosts = $this->config->hostNormaliser();
        $this->articleKeys = $this->config->articleKeyBuilder($this->hosts);
        $this->validator = new TargetValidator($this->google, $this->hosts, $this->config->ownHosts, $this->config->allowPrivateTargets);
        $this->decoder = new LegacyIdDecoder();
        $this->html = new HtmlTargetExtractor($this->validator);
        $this->guard = $addressGuard ?? new AddressGuard();
        $this->breaker = new CircuitBreaker(
            $this->clock,
            $this->config->breakerThreshold,
            $this->config->breakerWindow,
            $this->config->breakerCooldown,
        );
    }

    /** curl when ext-curl is loaded, else PHP streams. */
    public static function defaultTransport(): Transport
    {
        return CurlTransport::isAvailable() ? new CurlTransport() : new StreamTransport();
    }

    /**
     * Resolve one URL. Never throws for bad input.
     *
     * @param string|null $baseUrl base for relative Google News links (shape S8)
     * @param bool|null $generic follow redirects for non-Google URLs; null uses the config
     */
    public function resolve(string $url, ?string $baseUrl = null, ?bool $generic = null): Result
    {
        return $this->drive($this->pipeline($url, $baseUrl, $generic ?? $this->config->genericMode));
    }

    /**
     * The publisher URL when resolved, else the input unchanged.
     */
    public function resolveOrSame(string $url, ?string $baseUrl = null, ?bool $generic = null): string
    {
        return $this->resolve($url, $baseUrl, $generic)->output;
    }

    /**
     * Resolve a list. Results are keyed by input URL, in input order;
     * a URL given twice is resolved once.
     *
     * With a ConcurrentTransport (curl) and concurrency above 1, up to
     * that many links are in flight at once through curl_multi. Otherwise
     * links run one after another with the configured pause.
     *
     * @param iterable<string> $urls
     * @return array<string, Result>
     */
    public function resolveMany(iterable $urls, ?string $baseUrl = null, ?bool $generic = null): array
    {
        $inputs = [];
        foreach ($urls as $url) {
            $inputs[(string) $url] = true;
        }
        $inputs = array_map('strval', array_keys($inputs));
        $generic ??= $this->config->genericMode;

        if ($this->transport instanceof ConcurrentTransport && $this->config->concurrency > 1) {
            return $this->resolveConcurrently($this->transport, $inputs, $baseUrl, $generic);
        }

        $out = [];
        foreach ($inputs as $input) {
            $out[$input] = $this->resolve($input, $baseUrl, $generic);
        }

        return $out;
    }

    /** Detection only, no network. */
    public function detect(string $url, ?string $baseUrl = null): Detection
    {
        return $this->detector->detect($url, $baseUrl);
    }

    /** Outlet key of a URL or host, with this resolver's settings. */
    public function outletKey(string $urlOrHost): string
    {
        return $this->hosts->key($urlOrHost);
    }

    /** Article key of a URL, with this resolver's settings. */
    public function articleKey(string $url): string
    {
        return $this->articleKeys->key($url);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function hostNormaliser(): HostNormaliser
    {
        return $this->hosts;
    }

    public function articleKeyBuilder(): ArticleKeyBuilder
    {
        return $this->articleKeys;
    }

    public function circuitBreaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * Counters since this object was built: links seen, wrappers seen,
     * requests made, cache hits, resolved by method, unresolved by reason.
     *
     * @return array{links: int, wrappers: int, requests: int, cache_hits: int, resolved: array<string, int>, unresolved: array<string, int>}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    // ------------------------------------------------------------------
    // Drivers
    // ------------------------------------------------------------------

    /**
     * @param \Generator<int, Request, Response|TransportException, Result> $machine
     */
    private function drive(\Generator $machine): Result
    {
        $first = true;
        while ($machine->valid()) {
            $request = $machine->current();
            if ($first && $this->networkUsed && $this->config->pauseMs > 0) {
                $this->clock->sleep($this->config->pauseMs);
            }
            $first = false;
            $this->networkUsed = true;
            $machine->send($this->sendOne($request));
        }

        return $machine->getReturn();
    }

    /**
     * @param list<string> $inputs
     * @return array<string, Result>
     */
    private function resolveConcurrently(ConcurrentTransport $transport, array $inputs, ?string $baseUrl, bool $generic): array
    {
        $results = [];
        $queue = $inputs;
        /** @var array<int, array{input: string, machine: \Generator<int, Request, Response|TransportException, Result>}> $active */
        $active = [];
        $slot = 0;

        while ($queue !== [] || $active !== []) {
            while ($queue !== [] && count($active) < $this->config->concurrency) {
                $input = (string) array_shift($queue);
                $machine = $this->pipeline($input, $baseUrl, $generic);
                if ($machine->valid()) {
                    $active[$slot++] = ['input' => $input, 'machine' => $machine];
                } else {
                    $results[$input] = $machine->getReturn();
                }
            }
            if ($active === []) {
                continue;
            }

            if ($this->networkUsed && $this->config->pauseMs > 0) {
                $this->clock->sleep($this->config->pauseMs);
            }
            $this->networkUsed = true;

            $requests = [];
            foreach ($active as $key => $entry) {
                $requests[$key] = $entry['machine']->current();
            }
            $responses = $this->sendMany($transport, $requests);

            foreach ($active as $key => $entry) {
                $response = $responses[$key] ?? TransportException::network('Transport returned no response for this request.');
                if (!$response instanceof Response && !$response instanceof TransportException) {
                    throw new TransportContractException('sendMany() must return Response or TransportException values.');
                }
                $entry['machine']->send($response);
                if (!$entry['machine']->valid()) {
                    $results[$entry['input']] = $entry['machine']->getReturn();
                    unset($active[$key]);
                }
            }
        }

        $ordered = [];
        foreach ($inputs as $input) {
            $ordered[$input] = $results[$input];
        }

        return $ordered;
    }

    /**
     * sendMany() with the same isolation as sendOne(): a transport that
     * throws fails every request of this round with network_error instead
     * of escaping resolveMany(). Per-request failures stay per request.
     *
     * @param array<int, Request> $requests
     * @return array<array-key, mixed>
     */
    private function sendMany(ConcurrentTransport $transport, array $requests): array
    {
        try {
            return $transport->sendMany($requests);
        } catch (\TypeError $e) {
            throw new TransportContractException('ConcurrentTransport::sendMany() must return an array: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $error = $e instanceof TransportException ? $e : TransportException::network(get_class($e) . ': ' . $e->getMessage(), $e);

            return array_fill_keys(array_keys($requests), $error);
        }
    }

    private function sendOne(Request $request): Response|TransportException
    {
        try {
            return $this->transport->send($request);
        } catch (TransportException $e) {
            return $e;
        } catch (\Throwable $e) {
            if ($e instanceof \TypeError) {
                throw new TransportContractException('Transport::send() must return a Response: ' . $e->getMessage(), 0, $e);
            }

            return TransportException::network(get_class($e) . ': ' . $e->getMessage(), $e);
        }
    }

    // ------------------------------------------------------------------
    // Pipeline
    // ------------------------------------------------------------------

    /**
     * @return \Generator<int, Request, Response|TransportException, Result>
     */
    private function pipeline(string $input, ?string $baseUrl, bool $generic): \Generator
    {
        $run = new LinkRun($this->clock->now());
        $this->stats['links']++;
        $detection = $this->detector->detect($input, $baseUrl);

        if (!$detection->isWrapper) {
            if ($generic && $detection->reason === Reason::NotGoogleNews && $detection->url !== null) {
                $start = (string) preg_replace('/#.*$/s', '', $detection->url);
                $key = 'generic:' . $start;
                $cached = $this->fromCache($key, $input, $run, null);
                if ($cached !== null) {
                    return $this->finish($cached);
                }
                $outcome = yield from $this->genericRedirect($run, $start);
                $result = $this->build($run, $input, $outcome, null);
                $this->store($key, $result, $run);

                return $this->finish($result);
            }

            return $this->finish($this->build($run, $input, new Outcome(null, null), $detection->shape, $detection->reason));
        }

        $this->stats['wrappers']++;
        $key = 'wrapper:' . $detection->normalised;
        $cached = $this->fromCache($key, $input, $run, $detection->shape);
        if ($cached !== null) {
            return $this->finish($cached);
        }

        $outcome = yield from $this->resolveWrapper($run, $detection, 1);
        $result = $this->build($run, $input, $outcome, $detection->shape);
        $this->store($key, $result, $run);

        return $this->finish($result);
    }

    /**
     * @return \Generator<int, Request, Response|TransportException, Outcome>
     */
    private function resolveWrapper(LinkRun $run, Detection $detection, int $level): \Generator
    {
        $shape = $detection->shape;
        if ($shape !== null && !$shape->hasArticleId()) {
            if ($this->config->isEnabled(Method::QueryParam)) {
                $outcome = yield from $this->queryParam($run, $detection, $level);
                if ($outcome !== null) {
                    return $outcome;
                }
            }

            return yield from $this->network($run, $detection, false);
        }

        if ($this->config->isEnabled(Method::OfflineDecode) && $detection->articleId !== null) {
            $decoded = $this->decoder->decode($detection->articleId);
            if ($decoded->url !== null) {
                $accepted = $this->validator->accept($decoded->url, $detection->normalised);
                if ($accepted !== null) {
                    $run->succeed(Method::OfflineDecode);
                    if ($decoded->ampUrl !== null) {
                        $run->ampUrl = $this->validator->accept($decoded->ampUrl, $detection->normalised);
                    }

                    return new Outcome($accepted, Method::OfflineDecode);
                }
                $run->fail(Method::OfflineDecode, Reason::BadTarget);
            } else {
                $run->fail(Method::OfflineDecode, $decoded->reason ?? Reason::DecodeFailed);
            }
        }

        return yield from $this->network($run, $detection, true);
    }

    /**
     * Returns an Outcome when the method settled the link (resolved, or a
     * nested article wrapper was handed on), null to fall through.
     *
     * @return \Generator<int, Request, Response|TransportException, Outcome|null>
     */
    private function queryParam(LinkRun $run, Detection $detection, int $level): \Generator
    {
        $value = (string) $detection->target;
        while (true) {
            $value = self::stripLeakedGoogleParams(trim($value));
            $inner = $this->wideDetector->detect($value);
            if ($inner->isWrapper && $inner->shape !== null) {
                if ($inner->shape->hasArticleId()) {
                    $run->succeed(Method::QueryParam);

                    return yield from $this->resolveWrapper($run, $inner, $level + 1);
                }
                if ($level >= self::MAX_NESTING) {
                    $run->fail(Method::QueryParam, Reason::BadTarget);

                    return null;
                }
                $level++;
                $value = (string) $inner->target;
                continue;
            }
            $accepted = $this->validator->accept($value, $detection->normalised);
            if ($accepted === null) {
                $run->fail(Method::QueryParam, Reason::BadTarget);

                return null;
            }
            $run->succeed(Method::QueryParam);

            return new Outcome($accepted, Method::QueryParam);
        }
    }

    /**
     * batch_execute, http_redirect, then html_parse on whatever page was fetched.
     *
     * @return \Generator<int, Request, Response|TransportException, Outcome>
     */
    private function network(LinkRun $run, Detection $detection, bool $hasId): \Generator
    {
        $batch = $hasId && $this->config->isEnabled(Method::BatchExecute);
        $redirect = $this->config->isEnabled(Method::HttpRedirect);

        if ($this->breaker->isOpen()) {
            if ($batch) {
                $run->fail(Method::BatchExecute, Reason::CircuitOpen);
            }
            if ($redirect) {
                $run->fail(Method::HttpRedirect, Reason::CircuitOpen);
            }
        } else {
            if ($batch && $detection->articleId !== null) {
                $target = yield from $this->batchExecute($run, $detection->articleId, (string) $detection->normalised);
                if ($target !== null) {
                    return new Outcome($target, Method::BatchExecute);
                }
            }
            if ($redirect && !$run->rateLimited) {
                $target = yield from $this->httpRedirect($run, (string) $detection->normalised);
                if ($target !== null) {
                    return new Outcome($target, Method::HttpRedirect);
                }
            }
        }

        if ($this->config->isEnabled(Method::HtmlParse)) {
            $page = $run->page ?? $run->batchPage;
            if ($page !== null) {
                $target = $this->html->extract($page['body'], $page['url'], $detection->normalised);
                if ($target !== null) {
                    $run->succeed(Method::HtmlParse);

                    return new Outcome($target, Method::HtmlParse);
                }
                $run->fail(Method::HtmlParse, Reason::NoCandidate);
            }
        }

        return new Outcome(null, null);
    }

    /**
     * UNDOCUMENTED GOOGLE ENDPOINT, liable to break. See BatchExecute.
     *
     * @return \Generator<int, Request, Response|TransportException, string|null>
     */
    private function batchExecute(LinkRun $run, string $articleId, string $wrapper): \Generator
    {
        $method = Method::BatchExecute;
        $pageUrl = BatchExecute::pageUrl($articleId);
        $page = yield from $this->fetch($run, 'GET', $pageUrl);
        if ($page instanceof Reason) {
            $run->fail($method, $page);

            return null;
        }
        $reason = $this->googleResponseReason($run, $page, $pageUrl);
        if ($reason !== null) {
            $run->fail($method, $reason);

            return null;
        }
        if (!self::isHtml($page)) {
            $run->fail($method, Reason::UnexpectedContentType);

            return null;
        }
        $run->batchPage = ['url' => $pageUrl, 'body' => $page->body];

        $params = BatchExecute::pageParams($page->body);
        if ($params === null) {
            $run->fail($method, Reason::SignatureMissing);

            return null;
        }

        $post = yield from $this->fetch(
            $run,
            'POST',
            BatchExecute::ENDPOINT,
            ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept' => '*/*'],
            BatchExecute::requestBody($params['id'] ?? $articleId, $params['timestamp'], $params['signature']),
        );
        if ($post instanceof Reason) {
            $run->fail($method, $post);

            return null;
        }
        $reason = $this->googleResponseReason($run, $post, BatchExecute::ENDPOINT);
        if ($reason !== null) {
            $run->fail($method, $reason);

            return null;
        }

        $target = BatchExecute::parseResponse($post->body);
        if ($target === null) {
            $run->fail($method, Reason::FormatChanged);

            return null;
        }
        $accepted = $this->validator->accept($target, $wrapper);
        if ($accepted === null) {
            $run->fail($method, Reason::BadTarget);

            return null;
        }
        $run->succeed($method);

        return $accepted;
    }

    /**
     * Follow Location headers between Google hosts and stop at the first
     * hop that leaves Google. The publisher is never requested.
     *
     * @return \Generator<int, Request, Response|TransportException, string|null>
     */
    private function httpRedirect(LinkRun $run, string $start): \Generator
    {
        $method = Method::HttpRedirect;
        $url = self::https($start);
        $seen = [$url => true];
        $hops = 0;

        while (true) {
            $response = yield from $this->fetch($run, 'GET', $url);
            if ($response instanceof Reason) {
                $run->fail($method, $response);

                return null;
            }

            if ($response->isRedirect()) {
                $next = $this->nextHop($url, $response);
                if ($next instanceof Reason) {
                    $run->fail($method, $next);

                    return null;
                }
                $parts = Url::parse($next);
                $host = $parts === null ? '' : Url::normaliseHost($parts['host']);

                if (GoogleHosts::isSorryPage($next)) {
                    $this->rateLimited($run);
                    $run->fail($method, Reason::RateLimited);

                    return null;
                }
                if (GoogleHosts::isConsentHost($host)) {
                    $continue = Url::queryParam($parts['query'] ?? null, 'continue');
                    $accepted = $continue === null ? null : $this->validator->accept($continue, $start);
                    if ($accepted === null) {
                        $run->fail($method, Reason::ConsentWall);

                        return null;
                    }
                    $run->succeed($method);

                    return $accepted;
                }
                if (GoogleHosts::isLoginHost($host)) {
                    $run->fail($method, Reason::LoginWall);

                    return null;
                }

                $hops++;
                if ($hops > $this->config->maxRedirects) {
                    $run->fail($method, Reason::TooManyRedirects);

                    return null;
                }

                if ($this->google->isGoogle($host)) {
                    $next = self::https($next);
                    if (isset($seen[$next])) {
                        $run->fail($method, Reason::RedirectLoop);

                        return null;
                    }
                    $seen[$next] = true;
                    $url = $next;
                    continue;
                }

                $accepted = $this->validator->accept($next, $start);
                if ($accepted === null) {
                    $run->fail($method, Reason::BadTarget);

                    return null;
                }
                if (str_starts_with(Url::lower($accepted), 'http:') && str_starts_with($url, 'https:')) {
                    $run->insecureTarget = true;
                }
                $run->succeed($method);

                return $accepted;
            }

            $reason = $this->googleResponseReason($run, $response, $url);
            if ($reason !== null) {
                $run->fail($method, $reason);

                return null;
            }
            if (!self::isHtml($response)) {
                $run->fail($method, Reason::UnexpectedContentType);

                return null;
            }
            $run->page = ['url' => $url, 'body' => $response->body];
            $run->fail($method, Reason::NotRedirected);

            return null;
        }
    }

    /**
     * Generic mode: follow redirects for any URL until a non-redirect
     * answer. Refuses private and metadata addresses at every hop.
     *
     * @return \Generator<int, Request, Response|TransportException, Outcome>
     */
    private function genericRedirect(LinkRun $run, string $start): \Generator
    {
        $method = Method::HttpRedirect;
        $url = $start;
        $seen = [$url => true];
        $hops = 0;
        $previous = $url;

        while (true) {
            $parts = Url::parse($url);
            if ($parts === null || !Url::hasPlainAuthority($url)) {
                $run->fail($method, Reason::BadTarget);

                return new Outcome(null, null);
            }
            if ($this->guard->isBlocked($parts['host'])) {
                $run->fail($method, Reason::PrivateAddress);

                return new Outcome(null, null);
            }
            $response = yield from $this->fetch($run, 'GET', $url, [], null, self::GENERIC_BODY_BYTES, false);
            if ($response instanceof Reason) {
                $run->fail($method, $response);

                return new Outcome(null, null);
            }
            if (!$response->isRedirect()) {
                break;
            }
            $next = $this->nextHop($url, $response);
            if ($next instanceof Reason) {
                $run->fail($method, $next);

                return new Outcome(null, null);
            }
            $hops++;
            if ($hops > $this->config->maxRedirects) {
                $run->fail($method, Reason::TooManyRedirects);

                return new Outcome(null, null);
            }
            if (isset($seen[$next])) {
                $run->fail($method, Reason::RedirectLoop);

                return new Outcome(null, null);
            }
            $seen[$next] = true;
            $previous = $url;
            $url = $next;
        }

        if ($hops === 0) {
            $run->fail($method, Reason::NotRedirected);

            return new Outcome(null, null);
        }
        $accepted = $this->validator->accept($url, $start);
        if ($accepted === null) {
            $run->fail($method, Reason::BadTarget);

            return new Outcome(null, null);
        }
        if (str_starts_with(Url::lower($accepted), 'http:') && str_starts_with(Url::lower($previous), 'https:')) {
            $run->insecureTarget = true;
        }
        $run->succeed($method);

        return new Outcome($accepted, $method);
    }

    /**
     * One request, with the per-link budget, the breaker and the GET memo.
     *
     * @param array<string, string> $headers
     * @return \Generator<int, Request, Response|TransportException, Response|Reason>
     */
    private function fetch(
        LinkRun $run,
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?int $maxBytes = null,
        bool $google = true,
    ): \Generator {
        if ($method === 'GET' && isset($run->memo[$url])) {
            return $run->memo[$url];
        }
        if ($google && $this->breaker->isOpen()) {
            return Reason::CircuitOpen;
        }
        $remaining = $this->config->budget - ($this->clock->now() - $run->start);
        if ($remaining <= 0) {
            return Reason::BudgetExceeded;
        }

        $request = new Request(
            $method,
            $url,
            $headers + [
                'User-Agent' => $this->config->userAgentHeader(),
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => $this->config->acceptLanguage,
            ],
            $body,
            min($this->config->connectTimeout, $remaining),
            min($this->config->requestTimeout, $remaining),
            $maxBytes ?? $this->config->maxBodyBytes,
        );
        $run->requests++;
        $this->stats['requests']++;

        $response = yield $request;

        if ($response instanceof TransportException) {
            if ($response->isTimeout()) {
                return ($this->clock->now() - $run->start) >= $this->config->budget
                    ? Reason::BudgetExceeded
                    : Reason::Timeout;
            }

            return Reason::NetworkError;
        }
        if ($method === 'GET') {
            $run->memo[$url] = $response;
        }

        return $response;
    }

    /**
     * Reason for a Google response that is not a usable 200, or null.
     * Redirects reaching here are unexpected (batch_execute does not follow
     * them) except the sorry page and consent wall, which are named.
     */
    private function googleResponseReason(LinkRun $run, Response $response, string $url): ?Reason
    {
        if ($response->isRedirect()) {
            $next = Url::resolve($url, $response->header('location') ?? '');
            if ($next !== null && GoogleHosts::isSorryPage($next)) {
                $this->rateLimited($run);

                return Reason::RateLimited;
            }
            if ($next !== null && ($p = Url::parse($next)) !== null && GoogleHosts::isConsentHost($p['host'])) {
                return Reason::ConsentWall;
            }

            return Reason::UnexpectedStatus;
        }
        $status = $response->status;
        if ($status === 429) {
            $this->rateLimited($run);

            return Reason::RateLimited;
        }
        if ($status === 403) {
            return Reason::Forbidden;
        }
        if ($status === 404 || $status === 410) {
            return Reason::Gone;
        }
        if ($status >= 500) {
            return Reason::UpstreamError;
        }
        if ($status < 200 || $status > 299) {
            return Reason::UnexpectedStatus;
        }

        return null;
    }

    private function nextHop(string $current, Response $response): string|Reason
    {
        $location = $response->header('location');
        if ($location === null || trim($location) === '') {
            return Reason::UnexpectedStatus;
        }
        if (preg_match(self::BAD_SCHEMES, $location) === 1) {
            return Reason::BadTarget;
        }
        $next = Url::resolve($current, $location);
        if ($next === null) {
            return Reason::BadTarget;
        }
        $parts = Url::parse($next);
        if ($parts === null || ($parts['scheme'] !== 'http' && $parts['scheme'] !== 'https')) {
            return Reason::BadTarget;
        }

        return (string) preg_replace('/#.*$/s', '', $next);
    }

    private function rateLimited(LinkRun $run): void
    {
        if (!$run->rateLimited) {
            $run->rateLimited = true;
            $this->breaker->recordRateLimit();
        }
    }

    // ------------------------------------------------------------------
    // Results, cache, stats, logging
    // ------------------------------------------------------------------

    private function build(LinkRun $run, string $input, Outcome $outcome, ?Shape $shape, ?Reason $reason = null): Result
    {
        $elapsed = (int) round(($this->clock->now() - $run->start) * 1000);
        if ($outcome->target !== null && $outcome->method !== null) {
            return new Result(
                $input,
                $outcome->target,
                true,
                $outcome->method,
                null,
                null,
                $run->attempts,
                $run->requests,
                $elapsed,
                $this->hosts->key($outcome->target),
                false,
                $shape,
                $run->ampUrl,
                $run->insecureTarget,
            );
        }

        return new Result(
            $input,
            $input,
            false,
            Method::None,
            null,
            $reason ?? $run->lastReason() ?? Reason::NoCandidate,
            $run->attempts,
            $run->requests,
            $elapsed,
            $this->hosts->key($input),
            false,
            $shape,
        );
    }

    private function fromCache(string $key, string $input, LinkRun $run, ?Shape $shape): ?Result
    {
        if (!$this->config->isEnabled(Method::Cache)) {
            return null;
        }
        $cacheKey = self::CACHE_PREFIX . sha1($key);
        $data = $this->cache->get($cacheKey);
        if ($data === null) {
            return null;
        }
        $stored = Result::fromArray($data);
        if ($stored === null) {
            $this->cache->delete($cacheKey);

            return null;
        }
        if ($stored->resolved && $this->validator->accept($stored->output) === null) {
            // Fail closed on the target: a stored target that today's rules
            // reject (own hosts added since, a tampered store) is a miss.
            $this->cache->delete($cacheKey);

            return null;
        }
        $this->stats['cache_hits']++;
        $output = $stored->resolved ? $stored->output : $input;

        return new Result(
            $input,
            $output,
            $stored->resolved,
            $stored->resolved ? Method::Cache : Method::None,
            $stored->method === Method::None ? null : $stored->method,
            $stored->reason,
            [new Attempt(Method::Cache, null)],
            0,
            (int) round(($this->clock->now() - $run->start) * 1000),
            $this->hosts->key($output),
            !$stored->resolved,
            $shape ?? $stored->shape,
            $stored->ampUrl,
            $stored->insecureTarget,
        );
    }

    private function store(string $key, Result $result, LinkRun $run): void
    {
        if (!$this->config->isEnabled(Method::Cache)) {
            return;
        }
        $cacheKey = self::CACHE_PREFIX . sha1($key);
        if ($result->resolved) {
            $this->cache->set($cacheKey, $result->toArray(), $this->config->positiveTtl);
        } elseif (!$run->sawTransient() && ($result->reason === null || !$result->reason->isTransient())) {
            $this->cache->set($cacheKey, $result->toArray(), $this->config->negativeTtl);
        }
    }

    private function finish(Result $result): Result
    {
        if ($result->resolved) {
            $this->stats['resolved'][$result->method->value] = ($this->stats['resolved'][$result->method->value] ?? 0) + 1;
        } elseif ($result->reason !== null) {
            $this->stats['unresolved'][$result->reason->value] = ($this->stats['unresolved'][$result->reason->value] ?? 0) + 1;
        }

        if ($this->logger !== null) {
            $context = $result->toArray();
            $this->logger->log('debug', sprintf(
                'gnews-unwrap: %s via %s%s',
                $result->resolved ? 'resolved' : 'unresolved',
                $result->method->value,
                $result->reason === null ? '' : ' (' . $result->reason->value . ')'
            ), $context);
            $warned = [];
            foreach ($result->reasons() as $reason) {
                if ($reason->isWarning() && !isset($warned[$reason->value])) {
                    $warned[$reason->value] = true;
                    $this->logger->log('warning', 'gnews-unwrap: ' . $reason->value, $context);
                }
            }
        }

        return $result;
    }

    private static function isHtml(Response $response): bool
    {
        $type = $response->mediaType();

        return $type === '' || $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    private static function https(string $url): string
    {
        return (string) preg_replace('#^http://#i', 'https://', $url);
    }

    /**
     * Drop Google's own parameters (ct, cd, usg, ved, sa) when they leaked
     * into the decoded target.
     */
    private static function stripLeakedGoogleParams(string $url): string
    {
        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $rest = $hash === false ? $url : substr($url, 0, $hash);
        $q = strpos($rest, '?');
        if ($q === false) {
            return $url;
        }
        $base = substr($rest, 0, $q);
        $kept = [];
        foreach (Url::queryPairs(substr($rest, $q + 1)) as [$name, $value]) {
            if (in_array(Url::lower(urldecode($name)), self::LEAKED_GOOGLE_PARAMS, true)) {
                continue;
            }
            $kept[] = $value === null ? $name : $name . '=' . $value;
        }

        return $base . ($kept === [] ? '' : '?' . implode('&', $kept)) . $fragment;
    }
}
