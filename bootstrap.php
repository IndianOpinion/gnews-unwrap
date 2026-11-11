<?php

/**
 * Use gnews-unwrap without Composer:
 *
 *     require __DIR__ . '/path/to/gnews-unwrap/bootstrap.php';
 *
 * Registers a PSR-4 autoloader for IndianOpinion\GnewsUnwrap\ and nothing else.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\GnewsUnwrap\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
