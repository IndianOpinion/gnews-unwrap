<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Tests\Support;

use IndianOpinion\GnewsUnwrap\Transport\ConcurrentTransport;
use IndianOpinion\GnewsUnwrap\Transport\Request;
use IndianOpinion\GnewsUnwrap\Transport\Response;

/**
 * Breaks the ConcurrentTransport contract on purpose: sendMany() returns
 * strings. Excluded from static analysis for that reason.
 */
final class BrokenConcurrentTransport implements ConcurrentTransport
{
    public function send(Request $request): Response
    {
        return new Response(200, [], '', $request->url);
    }

    public function sendMany(array $requests): array
    {
        return array_map(static fn (Request $r): string => 'not a response', $requests);
    }
}
