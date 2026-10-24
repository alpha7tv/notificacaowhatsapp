<?php
declare(strict_types=1);

/*
 * API pública do app. O cliente vem em ?t=<endereço> (o Nginx preenche a partir de /r/<endereço>/).
 *   GET  api.php?t=x                 → rádios, estilos, publicidade ativa e aparência
 *   POST api.php?t=x&action=track    → registra visualização/clique de publicidade (id, type)
 *   GET  api.php                     → endereço principal: indica o app padrão, se houver
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/nowplaying.php';

header('X-Content-Type-Options: nosniff');

function api_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if (!headers_sent() && !preg_grep('/^Cache-Control:/i', headers_list())) {
        header('Cache-Control: no-cache');
    }
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

$action = (string) ($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $isPost ? ($_POST ?: (json_decode((string) file_get_contents('php://input'), true) ?: [])) : $_GET;

// ----- Música tocando (RDS) -----
if ($action === 'nowplaying') {
    $cfg = rp_config();
    $st = null;
    foreach ($cfg['stations'] as $x) {
        if ($x['id'] === (string) ($in['id'] ?? '') && !empty($x['active'])) {
            $st = $x;
        }
    }
    if (!$st) {
        api_out(['error' => 'Rádio não encontrada.'], 404);
    }
    $np = np_now($st, !empty($cfg['settings']['np_art']));
    header('Cache-Control: public, max-age=10');
    api_out([
        'song' => $np['song'], 'artist' => $np['artist'], 'title' => $np['title'],
        'art' => !empty($cfg['settings']['np_art']) ? $np['art'] : '',
        'at' => $np['at'], 'history' => array_slice($np['history'], 0, 10),
    ]);
}

// ----- Pedidos de música / oração -----
if ($action === 'request' && $isPost) {
    $s = rp_config()['settings'];
    $type = ($in['type'] ?? '') === 'prayer' ? 'prayer' : 'music';
    if (empty($s[$type === 'prayer' ? 'req_prayer' : 'req_music'])) {
        api_out(['error' => 'Recurso desativado.'], 403);
    }
    $device = rp_device($in['device'] ?? '');
    $name = rp_clean($in['name'] ?? '', 40);
    $text = rp_clean_text($in['text'] ?? '', 500);
    $song = rp_clean($in['song'] ?? '', 120);
    $city = rp_clean($in['city'] ?? '', 60);
    if ($name === '' || $city === '') {
        api_out(['error' => 'Informe seu nome e a cidade de onde está ouvindo.'], 422);
    }
    if ($type === 'prayer' ? $text === '' : $text === '' && $song === '') {
        api_out(['error' => $type === 'prayer' ? 'Escreva seu pedido de oração.' : 'Escreva a música que você quer ouvir.'], 422);
    }
    if (rp_is_banned($device)) {
        api_out(['error' => 'Envio bloqueado.'], 403);
    }
    // Por aparelho: 1 pedido a cada 30 s. Por IP (vários celulares podem dividir o mesmo IP): até 20 por minuto.
    if (($device && !rp_rate_ok('reqd:' . $device, 30)) || !rp_rate_count('reqi:' . rp_ip_hash(), 20, 60)) {
        api_out(['error' => 'Aguarde alguns segundos antes de enviar outro pedido.'], 429);
    }
    $req = rp_locked(function () use ($type, $device, $name, $city, $text, $song, $in) {
        $all = rp_read_json(rp_tfile('requests'), ['last' => 0, 'items' => []]);
        $req = [
            'id' => ++$all['last'],
            'type' => $type,
            'name' => $name,
            'city' => $city,
            'phone' => rp_clean($in['phone'] ?? '', 30),
            'song' => $type === 'music' ? $song : '',
            'text' => $text,
            'device' => $device,
            'ip' => rp_ip_hash(),
            'at' => time(),
            'status' => 'new',
            'reply' => '',
            'reply_at' => 0,
        ];
        $all['items'][] = $req;
        $all['items'] = array_slice($all['items'], -1000);
        rp_write_atomic(rp_tfile('requests'), json_encode($all, JSON_UNESCAPED_UNICODE));
        return $req;
    });
    $s = rp_config()['settings'];
    if ($type === 'music' && !empty($s['chat']) && !empty($s['chat_announce'])) {
        rp_chat_add(['kind' => 'system', 'nick' => $name, 'city' => $city, 'text' => 'pediu ' . ($req['song'] !== '' ? '"' . $req['song'] . '"' : 'uma música'), 'device' => $device]);
    }
    api_out(['ok' => true, 'id' => $req['id']]);
}

// Pedidos deste aparelho, com as respostas do locutor.
if ($action === 'my_requests') {
    $device = rp_device($in['device'] ?? '');
    $out = [];
    if ($device !== '') {
        foreach (array_reverse(rp_read_json(rp_tfile('requests'), ['items' => []])['items']) as $r) {
            if ($r['device'] === $device) {
                $out[] = ['id' => $r['id'], 'type' => $r['type'], 'song' => $r['song'], 'text' => $r['text'], 'at' => $r['at'], 'reply' => $r['reply'], 'reply_at' => $r['reply_at']];
                if (count($out) >= 20) {
                    break;
                }
            }
        }
    }
    api_out(['items' => $out]);
}

// ----- Chat dos ouvintes -----
if ($action === 'chat' || $action === 'chat_send') {
    $s = rp_config()['settings'];
    if (empty($s['chat'])) {
        api_out(['error' => 'O chat está desativado.', 'enabled' => false], 403);
    }
    $device = rp_device($in['device'] ?? '');
    $nick = rp_clean($in['nick'] ?? '', 24);
    $city = rp_clean($in['city'] ?? '', 40);
    if ($action === 'chat_send') {
        if (!$isPost || $device === '' || $nick === '' || $city === '') {
            api_out(['error' => 'Informe seu nome e sua cidade para conversar.'], 422);
        }
        $text = rp_clean($in['text'] ?? '', 300);
        if ($text === '') {
            api_out(['error' => 'Mensagem vazia.'], 422);
        }
        if (rp_is_banned($device)) {
            api_out(['error' => 'Você foi bloqueado neste chat.'], 403);
        }
        if (!rp_rate_ok('chat:' . $device, 2) || !rp_rate_count('chati:' . rp_ip_hash(), 60, 60)) {
            api_out(['error' => 'Calma! Espere um instante para mandar outra mensagem.'], 429);
        }
        $msg = rp_chat_add(['kind' => 'user', 'nick' => $nick, 'city' => $city, 'text' => $text, 'device' => $device]);
        rp_presence($device, $nick . ' · ' . $city);
        api_out(['ok' => true, 'id' => $msg['id']]);
    }
    $since = max(0, (int) ($in['since'] ?? 0));
    $data = rp_chat_since($since, false, $device);
    if ($since === 0) {
        $data['messages'] = array_slice($data['messages'], -60);
    }
    $data['online'] = rp_presence($device ?: null, $nick !== '' && $city !== '' ? $nick . ' · ' . $city : null);
    $data['banned'] = rp_is_banned($device);
    api_out($data);
}

if ($action === 'track') {
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

$tvs = [];
if (!empty($client['allow_tv']) || !array_key_exists('allow_tv', $client)) {
    foreach ($cfg['tvs'] as $tv) {
        if (empty($tv['active']) || empty($tv['src'])) {
            continue;
        }
        $tvs[] = [
            'id' => $tv['id'],
            'name' => $tv['name'],
            'type' => $tv['type'],
            'src' => $tv['src'],
            'logo' => $tv['logo'] ?? '',
            'genre' => $genreNames[$tv['genre'] ?? ''] ?? '',
            'description' => $tv['description'] ?? '',
        ];
    }
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

$used = array_unique([...array_column($stations, 'genre'), ...array_column($tvs, 'genre')]);
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
    'interact' => ['music' => !empty($s['req_music']), 'prayer' => !empty($s['req_prayer']), 'chat' => !empty($s['chat'])],
    'ad_rotate' => max(3, (int) $s['ad_rotate']),
    'popup_delay' => max(0, (int) $s['popup_delay']),
    'genres' => array_values(array_filter(array_column($cfg['genres'], 'name'), fn ($g) => in_array($g, $used, true))),
    'stations' => $stations,
    'tvs' => $tvs,
    'ads' => $ads,
    'updated_at' => $cfg['updated_at'] ?? 0,
];

api_out($out);
