<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * The batchexecute method, kept in one class so it can be fixed or switched
 * off in one place.
 *
 * UNDOCUMENTED AND LIABLE TO BREAK. This talks to an internal Google News
 * endpoint that every public decoder has used since September 2024. Google
 * does not document it, rate-limits it, and has changed it several times.
 * Heavy use draws HTTP 429. Complying with Google's terms is the caller's
 * job. Switch the method off with Config::withoutMethods(Method::BatchExecute).
 *
 * Steps:
 * 1. GET https://news.google.com/rss/articles/<id> and read the signature
 *    (data-n-a-sg) and timestamp (data-n-a-ts) attributes.
 * 2. POST a form-encoded f.req body naming RPC Fbv4je with a garturlreq
 *    payload (id, timestamp, signature).
 * 3. The answer starts with the anti-JSON prefix )]}' and carries the
 *    target inside a JSON string tagged garturlres. It is parsed as JSON,
 *    never searched with a regex for a URL.
 *
 * This class is pure: it builds requests and parses responses. The
 * resolver sends them.
 */
final class BatchExecute
{
    public const ENDPOINT = 'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je';
    public const RPC_ID = 'Fbv4je';
    private const JSON_DEPTH = 64;

    /** The article page. The /rss/ form is reported to draw fewer 429s. */
    public static function pageUrl(string $articleId): string
    {
        return 'https://news.google.com/rss/articles/' . $articleId;
    }

    /**
     * Signature, timestamp and (when present) the id the page itself names.
     *
     * @return array{signature: string, timestamp: string, id: ?string}|null null when an attribute is missing
     */
    public static function pageParams(string $html): ?array
    {
        $signature = self::attribute($html, 'data-n-a-sg');
        $timestamp = self::attribute($html, 'data-n-a-ts');
        if ($signature === null || $timestamp === null || preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_\-+\/=.]{1,4096}$/', $signature) !== 1) {
            return null;
        }
        $id = self::attribute($html, 'data-n-a-id');
        if ($id !== null && preg_match('/^[A-Za-z0-9_\-+]+={0,2}$/', $id) !== 1) {
            $id = null;
        }

        return ['signature' => $signature, 'timestamp' => $timestamp, 'id' => $id];
    }

    /** Form-encoded POST body for the Fbv4je RPC. */
    public static function requestBody(string $articleId, string $timestamp, string $signature): string
    {
        $inner = sprintf(
            '["garturlreq",[["X","X",["X","X"],null,null,1,1,"US:en",null,1,null,null,null,null,null,0,1],'
            . '"X","X",1,[1,1,1],1,1,null,0,0,null,0],%s,%s,%s]',
            json_encode($articleId, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $timestamp,
            json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $freq = json_encode([[[self::RPC_ID, $inner, null, 'generic']]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'f.req=' . rawurlencode($freq);
    }

    /**
     * The target URL from a batchexecute response, or null when the
     * response does not have the expected shape (format_changed).
     */
    public static function parseResponse(string $body): ?string
    {
        $body = ltrim($body);
        if (str_starts_with($body, ")]}'")) {
            $body = substr($body, 4);
        }
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $envelopes = [];
        $whole = self::json($body);
        if (is_array($whole)) {
            $envelopes[] = $whole;
        } else {
            // Chunked form: length lines followed by JSON lines.
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || ctype_digit($line)) {
                    continue;
                }
                $decoded = self::json($line);
                if (is_array($decoded)) {
                    $envelopes[] = $decoded;
                }
            }
        }

        foreach ($envelopes as $envelope) {
            foreach ($envelope as $entry) {
                if (!is_array($entry) || ($entry[0] ?? null) !== 'wrb.fr' || ($entry[1] ?? null) !== self::RPC_ID) {
                    continue;
                }
                if (!is_string($entry[2] ?? null)) {
                    return null;
                }
                $payload = self::json($entry[2]);
                if (!is_array($payload) || ($payload[0] ?? null) !== 'garturlres' || !is_string($payload[1] ?? null)) {
                    return null;
                }

                return $payload[1];
            }
        }

        return null;
    }

    private static function json(string $text): mixed
    {
        try {
            return json_decode($text, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function attribute(string $html, string $name): ?string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m) !== 1) {
            return null;
        }
        $value = html_entity_decode($m[1] . ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' ? null : $value;
    }
}
