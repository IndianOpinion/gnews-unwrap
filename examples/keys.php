<?php

/**
 * Outlet keys and article keys. Pure, no network.
 *
 *     php examples/keys.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\GnewsUnwrap\ArticleKeyBuilder;
use IndianOpinion\GnewsUnwrap\HostNormaliser;

$hosts = new HostNormaliser(aliases: ['business.example.com' => 'example.com']);
// AMP markers are kept by default; this example turns stripping on.
$keys = new ArticleKeyBuilder($hosts, stripAmpMarkers: true);

$urls = [
    'https://www.example.com/news/story-1/?utm_source=twitter',
    'http://m.example.com/news/story-1#comments',
    'https://amp.example.com/news/story-1/amp',
    'https://business.example.com/markets/today',
    'https://www.example.co.uk/world/report?id=42&fbclid=abc',
    'https://www2.example.co.uk/world/report?fbclid=xyz&id=42',
];

foreach ($urls as $url) {
    printf("%-60s outlet=%-15s key=%s\n", $url, $hosts->key($url), $keys->key($url));
}
