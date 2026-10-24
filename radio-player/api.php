<?php
declare(strict_types=1);

/*
 * API pública do app. O cliente vem em ?t=<endereço> (o Nginx preenche a partir de /r/<endereço>/).
 *   GET  api.php?t=x                 → rádios, estilos, publicidade ativa e aparência
 *   POST api.php?t=x&action=track    → registra visualização/clique de publicidade (id, type)
 *   GET  api.php                     → endereço principal: indica o app padrão, se houver
 */

require __DIR__ . '/lib/bootstrap.php';

header('X-Content-Type-Options: nosniff');

function api_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$platform = rp_platform();
$support = rp_whatsapp_link($platform['support_whatsapp']);
$slug = strtolower(trim((string) ($_GET['t'] ?? '')));
$clients = rp_clients();

if ($slug === '') {
    $def = $platform['default_tenant'];
    if ($def !== '' && isset($clients[$def])) {
        api_out(['redirect' => 'r/' . $def . '/']);
    }
    api_out(['error' => 'no_tenant', 'title' => $platform['brand'], 'message' => 'Nenhum aplicativo neste endereço.', 'support' => $support], 404);
}

$client = rp_valid_slug($slug) ? ($clients[$slug] ?? null) : null;
if (!$client) {
    api_out(['error' => 'not_found', 'title' => $platform['brand'], 'message' => 'Aplicativo não encontrado. Confira o link.', 'support' => $support], 404);
}
if (rp_client_state($client) !== 'active') {
    api_out(['error' => 'suspended', 'title' => $client['name'], 'message' => 'Este aplicativo está temporariamente indisponível.', 'support' => $support], 403);
}
rp_use_tenant($slug);

if (($_GET['action'] ?? '') === 'track') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = $_POST ?: (json_decode((string) file_get_contents('php://input'), true) ?: []);
        try {
            rp_track((string) ($in['id'] ?? ''), (string) ($in['type'] ?? ''));
        } catch (Throwable) {
            // estatística nunca pode derrubar o app
        }
    }
    http_response_code(204);
    exit;
}

$cfg = rp_config();
$s = $cfg['settings'];

$genreNames = array_column($cfg['genres'], 'name', 'id');
$stations = [];
foreach ($cfg['stations'] as $st) {
    if (empty($st['active']) || empty($st['stream'])) {
        continue;
    }
    $stations[] = [
        'id' => $st['id'],
        'name' => $st['name'],
        'stream' => $st['stream'],
        'logo' => $st['logo'] ?? '',
        'genre' => $genreNames[$st['genre'] ?? ''] ?? '',
        'city' => $st['city'] ?? '',
        'slogan' => $st['slogan'] ?? '',
        'metadata' => $st['metadata'] ?? '',
    ];
}

$ads = ['top' => [], 'player' => [], 'popup' => []];
$today = date('Y-m-d');
foreach ($cfg['ads'] as $ad) {
    if (isset($ads[$ad['position'] ?? '']) && rp_ad_is_live($ad, $today)) {
        $ads[$ad['position']][] = [
            'id' => $ad['id'],
            'title' => $ad['title'] ?? '',
            'text' => $ad['text'] ?? '',
            'image' => $ad['image'] ?? '',
            'link' => $ad['link'] ?? '',
        ];
    }
}

$used = array_unique(array_column($stations, 'genre'));
$out = [
    'title' => $s['title'],
    'description' => $s['description'],
    'theme' => rp_theme($s),
    'accent' => !empty($s['accent_custom']) ? $s['accent'] : '',
    'background' => $s['background'],
    'logo' => $s['logo'],
    'icon' => rp_icon('icon-192.png'),
    'about' => $s['about'],
    'contacts' => (object) array_filter([
        'whatsapp' => rp_whatsapp_link($s['whatsapp']),
        'instagram' => rp_social_link($s['instagram'], 'https://instagram.com/'),
        'facebook' => rp_social_link($s['facebook'], 'https://facebook.com/'),
        'youtube' => rp_social_link($s['youtube'], 'https://youtube.com/@'),
        'site' => rp_url($s['site']),
    ]),
    'ad_rotate' => max(3, (int) $s['ad_rotate']),
    'popup_delay' => max(0, (int) $s['popup_delay']),
    'genres' => array_values(array_filter(array_column($cfg['genres'], 'name'), fn ($g) => in_array($g, $used, true))),
    'stations' => $stations,
    'ads' => $ads,
    'updated_at' => $cfg['updated_at'] ?? 0,
];

api_out($out);
