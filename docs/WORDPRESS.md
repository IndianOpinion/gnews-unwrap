# Using gnews-unwrap from WordPress

gnews-unwrap has no WordPress dependency. This note shows how an importer
plugin or a must-use plugin calls it. Code here is an example to adapt, not
part of the package.

## Load it

With Composer in your plugin: `composer require indianopinion/gnews-unwrap`
and require your `vendor/autoload.php`. Without Composer, copy the package
into your plugin and:

```php
require_once __DIR__ . '/lib/gnews-unwrap/bootstrap.php';
```

## Where to call it

Call it in the import path, before the post or item is saved, and before
anything else is derived from the URL: dedup hashes, outlet counts, article
keys, the source link shown to readers.

Many feed importers expose a filter on the item before insert. The shape is
always the same:

```php
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Unwrapper;

function myplugin_unwrapper(): Unwrapper
{
    static $unwrapper = null;
    if ($unwrapper === null) {
        $config = (new Config())
            ->withOwnHosts([wp_parse_url(home_url(), PHP_URL_HOST)])
            ->withPauseMs(500);
        $unwrapper = new Unwrapper($config, null, new MyPlugin_Transient_Cache());
    }

    return $unwrapper;
}

/**
 * @param array{url: string} $item the importer's item, before it is saved
 */
function myplugin_unwrap_item(array $item): array
{
    $unwrapper = myplugin_unwrapper();
    $result = $unwrapper->resolve($item['url']);

    $item['url'] = $result->output;
    $item['meta']['_myplugin_source_url'] = $result->output;
    $item['meta']['_myplugin_outlet'] = $result->outletKey;
    $item['meta']['_myplugin_article_key'] = $unwrapper->articleKey($result->output);
    $item['meta']['_myplugin_unwrap'] = $result->resolved ? 'ok' : $result->reason?->value;

    return $item;
}
```

Store the resolved URL and the keys as post meta. Never re-resolve on read.

If unwrapping fails, keep the item. The meta says why; leave it out of outlet
counts until a later run resolves it. The own-host setting above matters:
a resolver that returns your own URL makes you the source of the
story.

## Back the cache with transients

```php
use IndianOpinion\GnewsUnwrap\Cache\Cache;

final class MyPlugin_Transient_Cache implements Cache
{
    private const PREFIX = 'myplugin_gnu_';

    public function get(string $key): ?array
    {
        $value = get_transient(self::PREFIX . md5($key));

        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        set_transient(self::PREFIX . md5($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient(self::PREFIX . md5($key));
    }
}
```

Transient names are limited to 172 characters, hence the hash. On plugin
uninstall, remove them:

```php
// uninstall.php
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_myplugin\\_gnu\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_myplugin\\_gnu\\_%'"
);
```

With a persistent object cache, transients live there instead and expire on
their own.

## Self-heal old posts from WP-CLI

Posts saved before you unwrapped carry the wrapper and the wrong outlet.
Re-run them and write back the URL and every value derived from it:

```php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('myplugin unwrap-heal', static function (array $args, array $assoc): void {
        $unwrapper = myplugin_unwrapper();
        $healed = 0;
        $paged = 1;
        do {
            $ids = get_posts([
                'post_type' => 'post',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'paged' => $paged++,
                'meta_key' => '_myplugin_source_url',
                'meta_compare' => 'LIKE',
                'meta_value' => 'google.com',
            ]);
            foreach ($ids as $id) {
                $stored = (string) get_post_meta($id, '_myplugin_source_url', true);
                $result = $unwrapper->resolve($stored);
                if (!$result->resolved || !$result->changed()) {
                    continue;
                }
                update_post_meta($id, '_myplugin_source_url', $result->output);
                update_post_meta($id, '_myplugin_outlet', $result->outletKey);
                update_post_meta($id, '_myplugin_article_key', $unwrapper->articleKey($result->output));
                $healed++;
            }
        } while ($ids !== []);
        WP_CLI::success(sprintf('Healed %d posts. Counters: %s', $healed, wp_json_encode($unwrapper->stats())));
    });
}
```

If the post body or a source list also holds the wrapper, rewrite those too.

## Keep it out of page views

Resolution can take seconds. Run it from the importer's cron, a queue such as
Action Scheduler, or WP-CLI. Never call it while rendering a page. If you must
react to a front-end event, schedule a single event and return.
