<?php

declare(strict_types=1);
use Fuxico\App;

// Fuxico - A malícia popular do boca a boca
// 100% 0x0.st & rustypaste protocol support

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
} else {
    // Standalone PSR-4 fallback autoloader
    spl_autoload_register(function (string $class) {
        if (str_starts_with($class, 'Fuxico\\')) {
            $rel = str_replace('\\', '/', substr($class, strlen('Fuxico\\')));
            $file = __DIR__.'/src/'.$rel.'.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

// Handle request routing
$app = new App;
$app->handleRequest();
