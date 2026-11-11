<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * Offline decoder for legacy Google News article ids.
 *
 * A legacy id is URL-safe base64 of a small protobuf message. The message
 * starts with field 1 (a varint) and then field 4 (a length-delimited
 * string), so the bytes begin 0x08 <varint> 0x22 and the id text usually
 * begins "CBMi". Field 4 holds the publisher URL; on some ids a later string
 * field holds the AMP URL.
 *
 * Since July 2024 field 4 holds an opaque token starting "AU_yqL" instead
 * of a URL. Those ids cannot be decoded offline (checked 11 November 2026):
 * the decoder reports opaque_id and the resolver moves on to the network.
 *
 * Any other leading bytes are reported as unknown_id_format and also sent
 * down the network path.
 *
 * Decoding is bounded: no allocation larger than the input, varints capped
 * at 5 bytes, lengths checked against the bytes left.
 */
final class LegacyIdDecoder
{
    public const OPAQUE_PREFIX = 'AU_yqL';
    private const MAX_ID_LENGTH = 4096;
    private const MAX_VARINT_BYTES = 5;

    public function decode(string $id): DecodedId
    {
        $bytes = self::base64($id);
        if ($bytes === null || $bytes === '') {
            return DecodedId::failed(Reason::DecodeFailed);
        }

        // Documented legacy layout: field 1 varint, then field 4 length-delimited.
        $offset = 0;
        $length = strlen($bytes);
        if (ord($bytes[0]) !== 0x08) {
            return DecodedId::failed(Reason::UnknownIdFormat);
        }
        $offset = 1;
        if (self::varint($bytes, $offset, $length) === null) {
            return DecodedId::failed(Reason::DecodeFailed);
        }
        if ($offset >= $length || ord($bytes[$offset]) !== 0x22) {
            return DecodedId::failed(Reason::UnknownIdFormat);
        }

        $strings = self::stringFields($bytes);
        if ($strings === []) {
            return DecodedId::failed(Reason::DecodeFailed);
        }
        $first = $strings[0];
        if ($first['field'] === 4 && str_starts_with($first['value'], self::OPAQUE_PREFIX)) {
            return DecodedId::failed(Reason::OpaqueId);
        }

        $canonical = null;
        $amp = null;
        foreach ($strings as $string) {
            if (!self::looksLikeHttpUrl($string['value'])) {
                continue;
            }
            if ($canonical === null) {
                $canonical = $string['value'];
            } elseif ($amp === null && $string['value'] !== $canonical) {
                $amp = $string['value'];
            }
        }
        if ($canonical === null) {
            return DecodedId::failed($first['field'] === 4 ? Reason::UnknownIdFormat : Reason::DecodeFailed);
        }

        return new DecodedId($canonical, $amp, null);
    }

    /**
     * Encode a URL the way legacy ids were built. Used by the test suite and
     * handy for building fixtures; not needed in production.
     */
    public static function encode(string $url, ?string $ampUrl = null, int $field1 = 0x13): string
    {
        $message = "\x08" . self::writeVarint($field1)
            . "\x22" . self::writeVarint(strlen($url)) . $url;
        if ($ampUrl !== null) {
            // Field 26, wire type 2: tag 0xd2 0x01.
            $message .= "\xd2\x01" . self::writeVarint(strlen($ampUrl)) . $ampUrl;
        }

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private static function base64(string $id): ?string
    {
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            return null;
        }
        $id = rtrim($id, '=');
        if (preg_match('/^[A-Za-z0-9_\-+\/]+$/', $id) !== 1) {
            return null;
        }
        $std = strtr($id, '-_', '+/');
        $pad = strlen($std) % 4;
        if ($pad === 1) {
            return null;
        }
        if ($pad > 0) {
            $std .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($std, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Walk the message and collect every length-delimited field in order.
     * Stops quietly at the first malformed field, keeping what was read.
     *
     * @return list<array{field: int, value: string}>
     */
    private static function stringFields(string $bytes): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $tag = self::varint($bytes, $offset, $length);
            if ($tag === null || $tag === 0) {
                break;
            }
            $field = $tag >> 3;
            $wire = $tag & 0x07;
            if ($wire === 0) {
                if (self::varint($bytes, $offset, $length) === null) {
                    break;
                }
            } elseif ($wire === 2) {
                $size = self::varint($bytes, $offset, $length);
                if ($size === null || $size > $length - $offset) {
                    break;
                }
                $out[] = ['field' => $field, 'value' => substr($bytes, $offset, $size)];
                $offset += $size;
            } elseif ($wire === 1) {
                if ($length - $offset < 8) {
                    break;
                }
                $offset += 8;
            } elseif ($wire === 5) {
                if ($length - $offset < 4) {
                    break;
                }
                $offset += 4;
            } else {
                break;
            }
        }

        return $out;
    }

    /** Read a varint of at most 5 bytes. Returns null when truncated or too long. */
    private static function varint(string $bytes, int &$offset, int $length): ?int
    {
        $value = 0;
        for ($i = 0; $i < self::MAX_VARINT_BYTES; $i++) {
            if ($offset >= $length) {
                return null;
            }
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function writeVarint(int $value): string
    {
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $out;
    }

    private static function looksLikeHttpUrl(string $value): bool
    {
        return preg_match('~^https?://[^\s/?#]+~i', $value) === 1;
    }
}
