<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * Sends one HTTP request and returns one response.
 *
 * Contract:
 * - Never follow redirects. Return the 3xx with its Location header; the
 *   resolver drives hops so it can apply limits and stop at the first
 *   non-Google host.
 * - Verify TLS. Keep no cookies between requests.
 * - Read at most $request->maxBodyBytes of body and set truncated.
 * - Return any HTTP status as a Response. Throw TransportException only when
 *   no response arrived (DNS, TLS, refused, timeout).
 */
interface Transport
{
    /** @throws TransportException */
    public function send(Request $request): Response;
}
