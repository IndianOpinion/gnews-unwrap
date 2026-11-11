<?php

/**
 * Resolve real links. Talks to Google: keep volumes low.
 *
 *     php examples/resolve-live.php 'https://news.google.com/rss/articles/CBMi...?oc=5'
 *
 * From EU and UK addresses Google answers with its consent page and the
 * result says consent_wall. See the README limitations.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Unwrapper;

$links = array_slice($argv, 1);
if ($links === []) {
    fwrite(STDERR, "usage: php examples/resolve-live.php <url> [<url> ...]\n");
    exit(2);
}

$unwrapper = new Unwrapper((new Config())->withPauseMs(500)->withConcurrency(1));
foreach ($unwrapper->resolveMany($links) as $result) {
    echo json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
}
