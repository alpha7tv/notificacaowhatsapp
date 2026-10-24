<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$slug = strtolower(trim((string) ($_GET['t'] ?? '')));
$client = rp_valid_slug($slug) ? (rp_clients()[$slug] ?? null) : null;
if ($client) {
    rp_use_tenant($slug);
    $s = rp_config()['settings'];
} else {
    $s = rp_defaults()['settings'];
    $s['title'] = rp_platform()['brand'];
}
$icon = fn (string $f) => $client ? rp_icon($f) : 'icons/' . $f;
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
    'background_color' => RP_THEMES[rp_theme($s)][2],
    'theme_color' => RP_THEMES[rp_theme($s)][2],
    'categories' => ['music', 'entertainment'],
    'icons' => [
        ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => $icon('maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
