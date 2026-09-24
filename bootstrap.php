<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    // Fallback: permite rodar sem "composer install" (ex.: ambiente local de testes).
    spl_autoload_register(static function (string $class): void {
        if (strncmp($class, 'App\\', 4) !== 0) {
            return;
        }
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require_once BASE_PATH . '/src/helpers.php';
}

App\Core\App::boot();
