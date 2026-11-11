<?php

/**
 * Resolve three links against recorded fixtures. No network.
 *
 *     php examples/fixtures-demo.php
 *
 * Read the method column: it says how each link was resolved.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\GnewsUnwrap\LegacyIdDecoder;
use IndianOpinion\GnewsUnwrap\Unwrapper;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

$fixtures = dirname(__DIR__) . '/tests/fixtures';
$transport = (new FakeTransport())
    ->load($fixtures . '/batch/ok.json')
    ->load($fixtures . '/http/302-publisher.json');

$unwrapper = new Unwrapper(null, $transport);

$links = [
    // A legacy id: the publisher URL is inside the id, no request needed.
    'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode('https://www.example.com/india/story-1') . '?oc=5',
    // An opaque id: resolved through batch_execute (recorded responses).
    'https://news.google.com/rss/articles/CBMiHEFVX3lxTE9wYXF1ZUZpeHR1cmVUb2tlbjAwMDE?oc=5',
    // An opaque id whose page answers with a redirect (recorded response).
    'https://news.google.com/rss/articles/CBMiHEFVX3lxTE9wYXF1ZUZpeHR1cmVUb2tlbjAwMDI',
];

printf("%-15s %-9s %-15s %s\n", 'method', 'requests', 'outlet', 'output');
foreach ($unwrapper->resolveMany($links) as $result) {
    printf(
        "%-15s %-9d %-15s %s\n",
        $result->resolved ? $result->method->value : 'none (' . $result->reason?->value . ')',
        $result->requests,
        $result->outletKey,
        $result->output
    );
}

echo PHP_EOL, 'Counters: ', json_encode($unwrapper->stats()), PHP_EOL;
