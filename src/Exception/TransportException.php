<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Exception;

/**
 * Thrown by a transport when a request could not complete. The resolver
 * catches it and turns it into a result with reason `timeout` or
 * `network_error`; it never reaches the caller of resolve().
 */
final class TransportException extends \RuntimeException implements GnewsUnwrapException
{
    public const NETWORK = 'network';
    public const TIMEOUT = 'timeout';

    private function __construct(string $message, private readonly string $kind, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function network(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::NETWORK, $previous);
    }

    public static function timeout(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::TIMEOUT, $previous);
    }

    public function isTimeout(): bool
    {
        return $this->kind === self::TIMEOUT;
    }
}
