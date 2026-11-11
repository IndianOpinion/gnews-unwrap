<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * Fallback transport on PHP's http stream wrapper, for hosts without
 * ext-curl. Needs allow_url_fopen and, for https, ext-openssl.
 *
 * - Timeouts go through the stream context. PHP uses the same context
 *   timeout for connecting and for each read, so the connect timeout cannot
 *   be set apart from the read timeout: this transport uses the request
 *   timeout for both, and checks the total deadline between body chunks.
 * - follow_location is off; the resolver handles redirects with its limit.
 * - The body is read in 8 KB chunks up to the byte cap.
 * - TLS peer and name verification are always on.
 */
final class StreamTransport implements Transport
{
    private const CHUNK = 8192;

    public static function isAvailable(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    public function send(Request $request): Response
    {
        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw TransportException::network('Only http and https URLs can be requested.');
        }
        $start = microtime(true);
        $deadline = $start + $request->timeout;

        $headerLines = ['Connection: close'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $http = [
            'method' => strtoupper($request->method),
            'header' => implode("\r\n", $headerLines),
            'timeout' => $request->timeout,
            'follow_location' => 0,
            'max_redirects' => 1,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ];
        if ($request->body !== null) {
            $http['content'] = $request->body;
        }
        $context = stream_context_create([
            'http' => $http,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $stream = fopen($request->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $message = (string) ($warning ?? 'fopen failed');
            if (stripos($message, 'timed out') !== false || microtime(true) >= $deadline) {
                throw TransportException::timeout('stream: ' . $message);
            }
            throw TransportException::network('stream: ' . $message);
        }

        try {
            $meta = stream_get_meta_data($stream);
            /** @var list<string> $lines */
            $lines = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            [$status, $headers] = self::parseHeaderLines($lines);

            $body = '';
            $truncated = false;
            while (!feof($stream)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw TransportException::timeout('stream: request timeout while reading the body');
                }
                $seconds = (int) floor($remaining);
                stream_set_timeout($stream, $seconds, (int) (($remaining - $seconds) * 1_000_000));
                $room = $request->maxBodyBytes - strlen($body);
                if ($room <= 0) {
                    $truncated = !feof($stream);
                    break;
                }
                $chunk = fread($stream, min(self::CHUNK, $room));
                if ($chunk === false) {
                    throw TransportException::network('stream: read failed');
                }
                $info = stream_get_meta_data($stream);
                if ($info['timed_out']) {
                    throw TransportException::timeout('stream: read timed out');
                }
                $body .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        return new Response($status, $headers, $body, $request->url, $truncated);
    }

    /**
     * Keeps only the last header block (the wrapper lists every block it saw).
     *
     * @param list<string> $lines
     * @return array{0: int, 1: array<string, list<string>>}
     */
    private static function parseHeaderLines(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
            }
        }

        return [$status, $headers];
    }
}
