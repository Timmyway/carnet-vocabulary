<?php
declare(strict_types=1);

// Minimal PSR-4 autoloader (App\ => src/). Swap for Composer's whenever you like.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
