<?php
// Roteador para testes locais: php -S localhost:8080 deploy/dev-router.php (dentro de radio-player/).
// Reproduz as regras do Nginx: /r/<cliente>/... aponta para os arquivos do app.

$root = dirname(__DIR__);
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('~^/(data|lib|deploy)(/|$)~', $uri)) {
    http_response_code(404);
    return true;
}
if (preg_match('~^/r/([a-z0-9-]+)$~', $uri, $m)) {
    header('Location: /r/' . $m[1] . '/', true, 301);
    return true;
}
if (preg_match('~^/r/([a-z0-9-]+)/(.*)$~', $uri, $m)) {
    $path = $m[2] === '' ? 'index.html' : $m[2];
    if (preg_match('~^(api|manifest)\.php$~', $path)) {
        $_GET['t'] = $m[1];
        chdir($root);
        require $root . '/' . $path;
        return true;
    }
    $file = realpath($root . '/' . $path);
    if (!$file || !str_starts_with($file, $root . '/') || !is_file($file) || preg_match('~/(data|lib|deploy)/~', substr($file, strlen($root)))) {
        http_response_code(404);
        return true;
    }
    $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp3' => 'audio/mpeg'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
return false;
