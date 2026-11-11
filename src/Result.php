<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * The outcome of one resolution. Immutable. Never null: resolve() always
 * returns one of these, even for junk input.
 */
final class Result
{
    /**
     * @param string $input the URL exactly as given
     * @param string $output the publisher URL when resolved, else the input
     * @param bool $resolved true only when output is an accepted publisher URL
     * @param Method $method the method that produced the output (none when unresolved)
     * @param Method|null $originalMethod for cache hits, the method that first resolved it
     * @param Reason|null $reason why it is not resolved (null when resolved)
     * @param list<Attempt> $attempts every method tried, in order
     * @param int $requests HTTP requests made for this call
     * @param int $elapsedMs wall time of this call
     * @param string $outletKey outlet key of the output URL
     * @param bool $fromNegativeCache true when a cached unresolved result was served
     * @param Shape|null $shape wrapper shape, when the input was a wrapper
     * @param string|null $ampUrl AMP URL carried by a legacy id, when present
     * @param bool $insecureTarget true when the final hop went from https to http
     */
    public function __construct(
        public readonly string $input,
        public readonly string $output,
        public readonly bool $resolved,
        public readonly Method $method,
        public readonly ?Method $originalMethod,
        public readonly ?Reason $reason,
        public readonly array $attempts,
        public readonly int $requests,
        public readonly int $elapsedMs,
        public readonly string $outletKey,
        public readonly bool $fromNegativeCache = false,
        public readonly ?Shape $shape = null,
        public readonly ?string $ampUrl = null,
        public readonly bool $insecureTarget = false,
    ) {
    }

    /** True when the output differs from the input. */
    public function changed(): bool
    {
        return $this->output !== $this->input;
    }

    /**
     * Reasons of every failed attempt, in order.
     *
     * @return list<Reason>
     */
    public function reasons(): array
    {
        $out = [];
        foreach ($this->attempts as $attempt) {
            if ($attempt->reason !== null) {
                $out[] = $attempt->reason;
            }
        }

        return $out;
    }

    /**
     * Plain array for storage and logs. fromArray() reverses it.
     *
     * @return array{input: string, output: string, resolved: bool, method: string, original_method: ?string,
     *     reason: ?string, attempts: list<array{method: string, reason: ?string}>, requests: int,
     *     elapsed_ms: int, outlet_key: string, from_negative_cache: bool, shape: ?string,
     *     amp_url: ?string, insecure_target: bool}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'resolved' => $this->resolved,
            'method' => $this->method->value,
            'original_method' => $this->originalMethod?->value,
            'reason' => $this->reason?->value,
            'attempts' => array_map(static fn (Attempt $a): array => $a->toArray(), $this->attempts),
            'requests' => $this->requests,
            'elapsed_ms' => $this->elapsedMs,
            'outlet_key' => $this->outletKey,
            'from_negative_cache' => $this->fromNegativeCache,
            'shape' => $this->shape?->value,
            'amp_url' => $this->ampUrl,
            'insecure_target' => $this->insecureTarget,
        ];
    }

    /**
     * Rebuild a result from toArray() output. Returns null for an array that
     * does not have the expected shape (for example a corrupt cache entry).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (
            !is_string($data['input'] ?? null) || !is_string($data['output'] ?? null)
            || !is_bool($data['resolved'] ?? null) || !is_string($data['method'] ?? null)
        ) {
            return null;
        }
        $method = Method::tryFrom($data['method']);
        if ($method === null) {
            return null;
        }
        $attempts = [];
        foreach (is_array($data['attempts'] ?? null) ? $data['attempts'] : [] as $row) {
            if (!is_array($row) || !is_string($row['method'] ?? null)) {
                return null;
            }
            $attemptMethod = Method::tryFrom($row['method']);
            if ($attemptMethod === null) {
                return null;
            }
            $attempts[] = new Attempt($attemptMethod, is_string($row['reason'] ?? null) ? Reason::tryFrom($row['reason']) : null);
        }

        return new self(
            $data['input'],
            $data['output'],
            $data['resolved'],
            $method,
            is_string($data['original_method'] ?? null) ? Method::tryFrom($data['original_method']) : null,
            is_string($data['reason'] ?? null) ? Reason::tryFrom($data['reason']) : null,
            $attempts,
            is_int($data['requests'] ?? null) ? $data['requests'] : 0,
            is_int($data['elapsed_ms'] ?? null) ? $data['elapsed_ms'] : 0,
            is_string($data['outlet_key'] ?? null) ? $data['outlet_key'] : '',
            is_bool($data['from_negative_cache'] ?? null) ? $data['from_negative_cache'] : false,
            is_string($data['shape'] ?? null) ? Shape::tryFrom($data['shape']) : null,
            is_string($data['amp_url'] ?? null) ? $data['amp_url'] : null,
            is_bool($data['insecure_target'] ?? null) ? $data['insecure_target'] : false,
        );
    }
}
