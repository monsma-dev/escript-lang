<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'EScript\\Compiler\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
