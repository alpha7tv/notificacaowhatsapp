<?php
// Roteador para o servidor embutido do PHP (somente desenvolvimento/testes locais):
//   php -S 127.0.0.1:8060 -t public tests/router.php          (painel)
//   FIBERLINK_SERVE=api php -S 127.0.0.1:8061 -t public tests/router.php   (API)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path) && !str_ends_with($path, '.php')) {
    return false;
}
if (getenv('FIBERLINK_SERVE') === 'api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/../public/api.php';
} else {
    require __DIR__ . '/../public/index.php';
}
