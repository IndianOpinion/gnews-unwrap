<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

/**
 * Per-handle buffers for CurlTransport.
 *
 * @internal
 */
final class CurlState
{
    /** @var array<string, list<string>> */
    public array $headers = [];
    public string $body = '';
    public bool $truncated = false;

    public function __construct(public readonly \CurlHandle $handle, private readonly int $maxBodyBytes)
    {
    }

    public function onHeader(\CurlHandle $handle, string $line): int
    {
        $length = strlen($line);
        $trimmed = trim($line);
        if (str_starts_with($trimmed, 'HTTP/')) {
            // A new status line (for example after 100 Continue) starts a new header block.
            $this->headers = [];

            return $length;
        }
        $colon = strpos($trimmed, ':');
        if ($colon !== false) {
            $name = strtolower(trim(substr($trimmed, 0, $colon)));
            $this->headers[$name][] = trim(substr($trimmed, $colon + 1));
        }

        return $length;
    }

    public function onBody(\CurlHandle $handle, string $chunk): int
    {
        $room = $this->maxBodyBytes - strlen($this->body);
        if (strlen($chunk) > $room) {
            $this->body .= substr($chunk, 0, max(0, $room));
            $this->truncated = true;

            // Returning a short count makes curl stop the transfer.
            return -1;
        }
        $this->body .= $chunk;

        return strlen($chunk);
    }
}
