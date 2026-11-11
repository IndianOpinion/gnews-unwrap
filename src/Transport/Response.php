<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

/**
 * The answer to one hop. Header names are stored lowercased.
 */
final class Response
{
    /** @var array<string, list<string>> */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     * @param bool $truncated true when the body was cut at the byte cap
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly string $url = '',
        public readonly bool $truncated = false,
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $key = strtolower(trim((string) $name));
            foreach ((array) $value as $one) {
                $normalised[$key][] = trim((string) $one);
            }
        }
        $this->headers = $normalised;
    }

    /** First value of a header, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true);
    }

    /** Media type without parameters, lowercased, or '' when absent. */
    public function mediaType(): string
    {
        $type = $this->header('content-type') ?? '';

        return strtolower(trim(explode(';', $type, 2)[0]));
    }
}
