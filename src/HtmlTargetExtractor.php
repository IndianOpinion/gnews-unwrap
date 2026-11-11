<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Looks for the publisher URL inside a Google interstitial page, in this
 * order: meta refresh, a canonical link off Google, a single anchor off
 * Google, a window.location or location.replace string literal. Every
 * candidate must pass the TargetValidator. Only the first 512 KB is read.
 */
final class HtmlTargetExtractor
{
    public const MAX_BYTES = 524_288;

    public function __construct(private readonly TargetValidator $validator)
    {
    }

    /**
     * @param string $html the interstitial body
     * @param string $pageUrl URL the body came from, for relative links
     * @param string|null $wrapper the normalised wrapper (never its own target)
     */
    public function extract(string $html, string $pageUrl, ?string $wrapper = null): ?string
    {
        $html = substr($html, 0, self::MAX_BYTES);

        foreach (
            [
            $this->metaRefresh($html),
            $this->canonical($html),
            ] as $candidates
        ) {
            foreach ($candidates as $candidate) {
                $accepted = $this->check($candidate, $pageUrl, $wrapper);
                if ($accepted !== null) {
                    return $accepted;
                }
            }
        }

        $anchor = $this->singleAnchor($html, $pageUrl, $wrapper);
        if ($anchor !== null) {
            return $anchor;
        }

        foreach ($this->locationLiterals($html) as $candidate) {
            $accepted = $this->check($candidate, $pageUrl, $wrapper);
            if ($accepted !== null) {
                return $accepted;
            }
        }

        return null;
    }

    private function check(string $candidate, string $pageUrl, ?string $wrapper): ?string
    {
        $absolute = Url::resolve($pageUrl, $candidate);
        if ($absolute === null) {
            return null;
        }

        return $this->validator->accept($absolute, $wrapper);
    }

    /** @return list<string> */
    private function metaRefresh(string $html): array
    {
        $out = [];
        foreach (self::tags($html, 'meta') as $attrs) {
            if (Url::lower($attrs['http-equiv'] ?? '') !== 'refresh' || !isset($attrs['content'])) {
                continue;
            }
            if (preg_match('/^\s*\d*(?:\.\d+)?\s*[;,]\s*url\s*=\s*([\'"]?)(.+?)\1\s*$/is', $attrs['content'], $m) === 1) {
                $out[] = trim($m[2]);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function canonical(string $html): array
    {
        $out = [];
        foreach (self::tags($html, 'link') as $attrs) {
            $rel = preg_split('/\s+/', Url::lower(trim($attrs['rel'] ?? ''))) ?: [];
            if (in_array('canonical', $rel, true) && isset($attrs['href'])) {
                $out[] = $attrs['href'];
            }
        }

        return $out;
    }

    private function singleAnchor(string $html, string $pageUrl, ?string $wrapper): ?string
    {
        $found = [];
        foreach (self::tags($html, 'a') as $attrs) {
            if (!isset($attrs['href'])) {
                continue;
            }
            $accepted = $this->check($attrs['href'], $pageUrl, $wrapper);
            if ($accepted !== null) {
                $found[$accepted] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** @return list<string> */
    private function locationLiterals(string $html): array
    {
        $out = [];
        $patterns = [
            '/\b(?:window\.|document\.|top\.)?location(?:\.href)?\s*=\s*([\'"])((?:\\\\.|(?!\1).){1,2048})\1/s',
            '/\b(?:window\.|document\.|top\.)?location\.(?:replace|assign)\s*\(\s*([\'"])((?:\\\\.|(?!\1).){1,2048})\1\s*\)/s',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $m) {
                    $out[] = self::unescapeJs($m[2]);
                }
            }
        }

        return $out;
    }

    private static function unescapeJs(string $value): string
    {
        $value = (string) preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})|\\\\x([0-9a-fA-F]{2})/',
            static function (array $m): string {
                $code = (int) hexdec($m[1] !== '' ? $m[1] : ($m[2] ?? '0'));

                return self::utf8($code);
            },
            $value
        );

        return (string) preg_replace('/\\\\(.)/s', '$1', $value);
    }

    private static function utf8(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }

        return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    /**
     * Attributes of every opening tag with the given name. A deliberately
     * small tokenizer: no DOM extension needed, entities decoded.
     *
     * @return list<array<string, string>>
     */
    private static function tags(string $html, string $name): array
    {
        $out = [];
        if (preg_match_all('/<' . $name . '\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $html, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $attrs = [];
                if (preg_match_all('/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', $raw, $pairs, PREG_SET_ORDER) > 0) {
                    foreach ($pairs as $pair) {
                        $key = Url::lower($pair[1]);
                        $value = ($pair[2] ?? '') . ($pair[3] ?? '') . ($pair[4] ?? '');
                        $attrs[$key] ??= html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                $out[] = $attrs;
            }
        }

        return $out;
    }
}
