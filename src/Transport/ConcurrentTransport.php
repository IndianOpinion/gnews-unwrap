<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * A transport that can run several requests at once. resolveMany() uses it
 * when the configured concurrency is above one.
 */
interface ConcurrentTransport extends Transport
{
    /**
     * Send every request and return one entry per key, in any order. A
     * request that failed maps to a TransportException instead of throwing.
     *
     * @param array<array-key, Request> $requests
     * @return array<array-key, Response|TransportException> same keys as $requests
     */
    public function sendMany(array $requests): array;
}
