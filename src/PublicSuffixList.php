<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Internal\Url;

/**
 * Optional full Public Suffix List, loaded from a file on disk (for
 * example public_suffix_list.dat from publicsuffix.org). The library never
 * downloads it: fetch and refresh it on your own schedule.
 *
 * Rules are matched on the ASCII (punycode) form of the host. Unicode rules
 * are converted when ext-intl is loaded and skipped otherwise.
 */
final class PublicSuffixList
{
    /** @var array<string, true> */
    private array $rules = [];
    /** @var array<string, true> */
    private array $wildcards = [];
    /** @var array<string, true> */
    private array $exceptions = [];

    private function __construct()
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidConfigException('Public Suffix List file is not readable: ' . $path);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new InvalidConfigException('Public Suffix List file could not be read: ' . $path);
        }

        return self::fromString($content);
    }

    public static function fromString(string $content): self
    {
        $list = new self();
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $rule = explode(' ', strtr($line, "\t", ' '), 2)[0];
            $exception = str_starts_with($rule, '!');
            if ($exception) {
                $rule = substr($rule, 1);
            }
            $wildcard = str_starts_with($rule, '*.');
            if ($wildcard) {
                $rule = substr($rule, 2);
            }
            $rule = self::toAscii($rule);
            if ($rule === null || $rule === '') {
                continue;
            }
            if ($exception) {
                $list->exceptions[$rule] = true;
            } elseif ($wildcard) {
                $list->wildcards[$rule] = true;
            } else {
                $list->rules[$rule] = true;
            }
        }
        if ($list->rules === [] && $list->wildcards === []) {
            throw new InvalidConfigException('Public Suffix List holds no rules.');
        }

        return $list;
    }

    /**
     * Number of labels that make up the public suffix of the host.
     * Follows the publicsuffix.org algorithm, with "*" as the default rule.
     *
     * @param list<string> $labels host labels, lowercased ASCII
     */
    public function suffixLabelCount(array $labels): int
    {
        $count = count($labels);
        $best = 1;
        for ($i = 0; $i < $count; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $size = $count - $i;
            if (isset($this->exceptions[$candidate])) {
                return $size - 1;
            }
            if (isset($this->rules[$candidate]) && $size > $best) {
                $best = $size;
            }
            if ($i + 1 < $count && isset($this->wildcards[implode('.', array_slice($labels, $i + 1))]) && $size > $best) {
                $best = $size;
            }
        }

        return min($best, $count);
    }

    private static function toAscii(string $rule): ?string
    {
        if (preg_match('/[^\x00-\x7F]/', $rule) !== 1) {
            return Url::lower($rule);
        }
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $ascii = idn_to_ascii($rule, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : Url::lower($ascii);
    }
}
