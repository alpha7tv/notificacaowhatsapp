<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$s = rp_config()['settings'];
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'name' => $s['title'],
    'short_name' => $s['short_name'] ?: mb_substr($s['title'], 0, 12),
    'description' => $s['description'],
    'lang' => 'pt-BR',
    'id' => './',
    'start_url' => './',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f1115',
    'theme_color' => $s['accent'],
    'categories' => ['music', 'entertainment'],
    'icons' => [
        ['src' => rp_icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => rp_icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => rp_icon('maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
