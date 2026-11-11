<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * Default transport, built on ext-curl.
 *
 * Redirects are never followed, TLS is always verified, no cookies are
 * kept, only http and https are allowed, and the body is cut at the
 * request's byte cap. Extra curl options (a proxy, an interface to bind)
 * can be passed in; options that would break these rules are refused.
 */
final class CurlTransport implements ConcurrentTransport
{
    private const CURLE_WRITE_ERROR = 23;
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** @var array<int, mixed> */
    private readonly array $extraOptions;

    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* values, for example a proxy
     * @param int $maxConcurrency upper bound on parallel handles in sendMany()
     */
    public function __construct(array $curlOptions = [], private readonly int $maxConcurrency = 8)
    {
        if (!self::isAvailable()) {
            throw new InvalidConfigException('CurlTransport needs ext-curl. Use StreamTransport instead.');
        }
        if ($maxConcurrency < 1) {
            throw new InvalidConfigException('CurlTransport maxConcurrency must be at least 1.');
        }
        $forbidden = [
            CURLOPT_FOLLOWLOCATION, CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST,
            CURLOPT_COOKIEFILE, CURLOPT_COOKIEJAR, CURLOPT_COOKIE, CURLOPT_PROTOCOLS,
            CURLOPT_REDIR_PROTOCOLS, CURLOPT_HEADERFUNCTION, CURLOPT_WRITEFUNCTION,
            CURLOPT_URL, CURLOPT_RETURNTRANSFER, CURLOPT_CUSTOMREQUEST, CURLOPT_POSTFIELDS,
            CURLOPT_HTTPHEADER, CURLOPT_CONNECTTIMEOUT_MS, CURLOPT_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT, CURLOPT_TIMEOUT,
        ];
        foreach (array_keys($curlOptions) as $option) {
            if (in_array($option, $forbidden, true)) {
                throw new InvalidConfigException(sprintf(
                    'Curl option %d is managed by CurlTransport and cannot be overridden.',
                    $option
                ));
            }
        }
        $this->extraOptions = $curlOptions;
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    public function send(Request $request): Response
    {
        $state = $this->open($request);
        curl_exec($state->handle);

        return $this->finish($state, curl_errno($state->handle), curl_error($state->handle));
    }

    public function sendMany(array $requests): array
    {
        $results = [];
        $queue = $requests;
        $multi = curl_multi_init();
        /** @var array<int, array{key: array-key, state: CurlState}> $active */
        $active = [];

        try {
            while ($queue !== [] || $active !== []) {
                while ($queue !== [] && count($active) < $this->maxConcurrency) {
                    $key = array_key_first($queue);
                    $request = $queue[$key];
                    unset($queue[$key]);
                    try {
                        $state = $this->open($request);
                    } catch (TransportException $e) {
                        $results[$key] = $e;
                        continue;
                    }
                    curl_multi_add_handle($multi, $state->handle);
                    $active[spl_object_id($state->handle)] = ['key' => $key, 'state' => $state];
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) {
                    foreach ($active as $entry) {
                        $results[$entry['key']] = TransportException::network('curl_multi failed: ' . curl_multi_strerror($status));
                        curl_multi_remove_handle($multi, $entry['state']->handle);
                    }
                    $active = [];
                    continue;
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);
                    if (!isset($active[$id])) {
                        continue;
                    }
                    $entry = $active[$id];
                    unset($active[$id]);
                    curl_multi_remove_handle($multi, $handle);
                    try {
                        $results[$entry['key']] = $this->finish($entry['state'], (int) $info['result'], curl_error($handle));
                    } catch (TransportException $e) {
                        $results[$entry['key']] = $e;
                    }
                }

                if ($running > 0) {
                    curl_multi_select($multi, 0.1);
                }
            }
        } finally {
            curl_multi_close($multi);
        }

        return $results;
    }

    private function open(Request $request): CurlState
    {
        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw TransportException::network('Only http and https URLs can be requested.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw TransportException::network('curl_init failed.');
        }
        $state = new CurlState($handle, $request->maxBodyBytes);

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = $this->extraOptions + [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => strtoupper($request->method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($request->connectTimeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => [$state, 'onHeader'],
            CURLOPT_WRITEFUNCTION => [$state, 'onBody'],
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if (strtoupper($request->method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if (!curl_setopt_array($handle, $options)) {
            throw TransportException::network('curl_setopt_array refused an option.');
        }

        return $state;
    }

    private function finish(CurlState $state, int $errno, string $error): Response
    {
        $handle = $state->handle;
        if ($errno !== 0 && !($errno === self::CURLE_WRITE_ERROR && $state->truncated)) {
            if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
                throw TransportException::timeout('curl: ' . $error);
            }
            throw TransportException::network(sprintf('curl error %d: %s', $errno, $error));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $url = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        return new Response($status, $state->headers, $state->body, $url, $state->truncated);
    }
}
