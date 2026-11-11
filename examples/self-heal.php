<?php

/**
 * Self-heal rows stored before unwrapping existed.
 *
 *     php examples/self-heal.php
 *
 * Reads stored source URLs, resolves them, and when the output differs
 * writes back the URL and every value derived from it. A row that is not
 * a wrapper comes back unchanged and costs nothing. Here the "table" is an
 * array and every wrapper uses a legacy id, so no request is made.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\GnewsUnwrap\LegacyIdDecoder;
use IndianOpinion\GnewsUnwrap\Unwrapper;
use IndianOpinion\GnewsUnwrap\Transport\FakeTransport;

$wrap = static fn (string $url): string => 'https://news.google.com/rss/articles/' . LegacyIdDecoder::encode($url) . '?oc=5';

$rows = [
    ['id' => 1, 'source_url' => 'https://www.example.com/a/story', 'outlet' => 'example.com'],
    ['id' => 2, 'source_url' => $wrap('https://m.example.co.uk/b/'), 'outlet' => 'news.google.com'],
    ['id' => 3, 'source_url' => $wrap('https://example.net/c'), 'outlet' => 'news.google.com'],
];

// FakeTransport with no routes: any network attempt fails, so this run is provably offline.
$unwrapper = new Unwrapper(null, new FakeTransport());

foreach ($rows as &$row) {
    $result = $unwrapper->resolve($row['source_url']);
    if (!$result->resolved || !$result->changed()) {
        continue; // Not a wrapper, or still unresolved: keep the row as it is and retry later.
    }
    $row['source_url'] = $result->output;
    $row['outlet'] = $result->outletKey;
    $row['article_key'] = $unwrapper->articleKey($result->output);
    printf("healed row %d -> %s (%s)\n", $row['id'], $row['source_url'], $row['outlet']);
}
unset($row);
