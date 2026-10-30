<?php

declare(strict_types=1);

// Minimal PSR-4 loader for the Vitalcare\ namespace. The server has no Composer, and the app
// has no third-party runtime dependencies, so nothing else is needed.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vitalcare\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
