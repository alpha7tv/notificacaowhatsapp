<?php
declare(strict_types=1);

/*
 * Núcleo do Rádio Player: armazenamento em arquivos (sem banco de dados),
 * uploads de imagens e helpers usados pela API e pelo painel.
 *
 * Plataforma com vários clientes: cada cliente tem um "endereço" (slug), um app
 * em /r/<slug>/ e os próprios dados em data/tenants/<slug>/ e uploads/<slug>/.
 */

date_default_timezone_set('America/Sao_Paulo');

const RP_ROOT = __DIR__ . '/..';
const RP_DATA = RP_ROOT . '/data';
const RP_UPLOADS = RP_ROOT . '/uploads';
const RP_TENANTS = RP_DATA . '/tenants';
const RP_CLIENTS = RP_DATA . '/clients.php';
const RP_PLATFORM = RP_DATA . '/platform.json';
const RP_ADMIN = RP_DATA . '/admin.php';
const RP_LOGIN = RP_DATA . '/login-attempts.php';
const RP_MAX_UPLOAD = 4 * 1024 * 1024;

const AD_POSITIONS = [
    'top' => 'Banner no topo da lista',
    'player' => 'Banner na tela do player',
    'popup' => 'Popup ao abrir o app',
];

/** Temas visuais do app: nome, descrição, cor de fundo, cor de destaque e prévia (CSS). */
const RP_THEMES = [
    'gospel' => ['Gospel', 'Claro e celestial, luz dourada e anel brilhante na capa.', '#eef1fb', '#9a6b14',
        'radial-gradient(110% 60% at 50% -10%, #ffd580, transparent 62%), linear-gradient(180deg, #f7f3ff, #dfe6fb)'],
    'sertanejo' => ['Sertanejo', 'Pôr do sol em tons quentes de laranja, moderno e marcante.', '#1c0d07', '#ff8a1f',
        'radial-gradient(95% 60% at 50% -5%, #ff8a24, transparent 65%), linear-gradient(180deg, #3d1708, #1c0d07)'],
    'poprock' => ['Pop Rock', 'Escuro com neon roxo e magenta, equalizador vibrante.', '#0e0717', '#ff2e93',
        'radial-gradient(75% 50% at 12% 8%, #a028ff, transparent 70%), radial-gradient(65% 45% at 92% 88%, #ff288c, transparent 70%), #0e0717'],
    'raiz' => ['Sertanejo Raiz', 'Madeira, couro e costura; capa como foto antiga.', '#2a1a0f', '#d9a441',
        'repeating-linear-gradient(90deg, transparent 0 14px, rgba(0,0,0,.3) 14px 15px), linear-gradient(180deg, #4b2d18, #2a1a0f)'],
    'forro' => ['Forró', 'Noite de São João com bandeirinhas coloridas.', '#0f1c48', '#ffd23f',
        'linear-gradient(90deg, #ff4d4d 0 25%, #ffd23f 25% 50%, #2ec4b6 50% 75%, #ff7ac6 75%) top / 100% 8px no-repeat, linear-gradient(180deg, #1b2f74, #0f1c48)'],
    'classico' => ['Clássico', 'Simples, segue o modo claro/escuro do aparelho.', '#0f1115', '#e5484d',
        'linear-gradient(135deg, #f4f5f7 50%, #0f1115 50%)'],
];

function rp_theme(array $s): string
{
    return isset(RP_THEMES[$s['theme'] ?? '']) ? $s['theme'] : 'poprock';
}

/** Cor de destaque efetiva: a escolhida no painel ou a do tema. */
function rp_accent(array $s): string
{
    return !empty($s['accent_custom']) ? $s['accent'] : RP_THEMES[rp_theme($s)][3];
}

function rp_defaults(): array
{
    $genres = [];
    foreach (['Gospel', 'Sertanejo', 'Pop Rock', 'Sertanejo Raiz', 'Forró'] as $name) {
        $genres[] = ['id' => rp_id(), 'name' => $name];
    }
    return [
        'settings' => [
            'title' => 'Rádio Player',
            'short_name' => 'Rádio',
            'description' => 'Ouça nossas rádios ao vivo, onde estiver.',
            'theme' => 'poprock',
            'accent' => '#e5484d',
            'accent_custom' => false,
            'background' => '',
            'logo' => '',
            'about' => '',
            'whatsapp' => '',
            'instagram' => '',
            'facebook' => '',
            'youtube' => '',
            'site' => '',
            'ad_rotate' => 8,
            'popup_delay' => 3,
            'req_music' => true,
            'req_prayer' => true,
            'chat' => true,
            'chat_announce' => true,
        ],
        'genres' => $genres,
        'stations' => [],
        'tvs' => [],
        'ads' => [],
        'updated_at' => time(),
    ];
}

function rp_id(): string
{
    return bin2hex(random_bytes(5));
}

// ---------- Clientes (tenants) ----------

const RP_RESERVED_SLUGS = ['admin', 'api', 'r', 'data', 'lib', 'uploads', 'samples', 'icons', 'css', 'js', 'deploy', 'www', 'app'];

function rp_valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug) && !in_array($slug, RP_RESERVED_SLUGS, true);
}

/** Sugere um endereço a partir do nome ("Rádio Nova FM" → "radio-nova-fm"). */
function rp_slugify(string $name): string
{
    $s = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return substr($s, 0, 40);
}

/** Define o cliente em uso; a partir daqui config, uploads e estatísticas são dele. */
function rp_use_tenant(?string $slug): void
{
    if ($slug !== null && !rp_valid_slug($slug)) {
        throw new InvalidArgumentException('Endereço de cliente inválido.');
    }
    $GLOBALS['rp_tenant'] = $slug;
}

function rp_tenant(): string
{
    $t = $GLOBALS['rp_tenant'] ?? null;
    if (!$t) {
        throw new LogicException('Nenhum cliente selecionado.');
    }
    return $t;
}

function rp_tenant_dir(): string
{
    return RP_TENANTS . '/' . rp_tenant();
}

function rp_upload_dir(): string
{
    return RP_UPLOADS . '/' . rp_tenant();
}

function rp_clients(): array
{
    return rp_read_php(RP_CLIENTS);
}

function rp_save_clients(array $clients): void
{
    ksort($clients);
    rp_write_php(RP_CLIENTS, $clients);
}

/** 'active', 'suspended' (suspenso no painel master) ou 'expired' (vencimento passou). */
function rp_client_state(array $c): string
{
    if (($c['status'] ?? 'active') === 'suspended') {
        return 'suspended';
    }
    if (!empty($c['expires']) && $c['expires'] < date('Y-m-d')) {
        return 'expired';
    }
    return 'active';
}

function rp_platform(): array
{
    return array_merge([
        'brand' => 'Rádio Player',
        'support_whatsapp' => '',
        'default_tenant' => '',
    ], rp_read_json(RP_PLATFORM, []) ?? []);
}

function rp_save_platform(array $p): void
{
    rp_write_atomic(RP_PLATFORM, json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Apaga uma pasta inteira (só dentro de data/tenants e uploads). */
function rp_rmtree(string $dir): void
{
    $real = realpath($dir);
    $allowed = [realpath(RP_TENANTS), realpath(RP_UPLOADS)];
    if (!$real || !is_dir($real) || !in_array(dirname($real), $allowed, true)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

/**
 * Atualização da versão de um cliente só para a plataforma: o app que já existia
 * vira o cliente "principal" e continua abrindo no endereço principal do site.
 */
function rp_migrate_single(): void
{
    if (!is_file(RP_DATA . '/config.json')) {
        return;
    }
    try {
        rp_locked(function () {
            if (!is_file(RP_DATA . '/config.json')) {
                return;
            }
            $slug = 'principal';
            $tdir = RP_TENANTS . '/' . $slug;
            if (is_file($tdir . '/config.json')) {
                // Já migrado antes: guarda o arquivo antigo sem sobrescrever nada.
                rename(RP_DATA . '/config.json', RP_DATA . '/config.json.migrado');
                return;
            }
            $udir = RP_UPLOADS . '/' . $slug;
            @mkdir($tdir, 0775, true);
            @mkdir($udir, 0775, true);
            foreach (glob(RP_UPLOADS . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    rename($f, $udir . '/' . basename($f));
                }
            }
            $json = (string) file_get_contents(RP_DATA . '/config.json');
            $json = str_replace('"uploads/', '"uploads/' . $slug . '/', $json);
            $cfg = json_decode($json, true) ?: [];
            rp_write_atomic($tdir . '/config.json', $json);
            @unlink(RP_DATA . '/config.json');
            if (is_file(RP_DATA . '/stats.json')) {
                rename(RP_DATA . '/stats.json', $tdir . '/stats.json');
            }
            $clients = rp_read_php(RP_CLIENTS);
            $clients[$slug] = [
                'name' => $cfg['settings']['title'] ?? 'Cliente principal',
                'user' => '', 'hash' => '', 'uid' => rp_id(),
                'status' => 'active', 'expires' => '', 'max_stations' => 0,
                'phone' => '', 'notes' => 'Criado automaticamente a partir da instalação anterior.',
                'created_at' => date('Y-m-d'),
            ];
            rp_save_clients($clients);
            $p = rp_platform();
            $p['default_tenant'] = $slug;
            rp_save_platform($p);
        });
    } catch (Throwable) {
        // Sem permissão de escrita: tenta de novo na próxima requisição.
    }
}

// ---------- Armazenamento ----------

function rp_ensure_dirs(): void
{
    $dirs = [RP_DATA, RP_UPLOADS, RP_TENANTS];
    if (!empty($GLOBALS['rp_tenant'])) {
        $dirs[] = rp_tenant_dir();
        $dirs[] = rp_upload_dir();
    }
    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException("Não foi possível criar a pasta {$dir}. Verifique as permissões.");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("A pasta " . basename($dir) . "/ precisa ter permissão de escrita para o servidor web.");
        }
    }
}

function rp_read_json(string $file, ?array $default = null): ?array
{
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function rp_write_atomic(string $file, string $content): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível salvar ' . basename($file) . '. Verifique as permissões da pasta data/.');
    }
    @chmod($file, 0664);
}

/**
 * Executa $fn com um lock exclusivo (evita que dois salvamentos simultâneos se percam).
 * Reentrante: chamadas aninhadas na mesma requisição reaproveitam o lock já obtido.
 */
function rp_locked(callable $fn)
{
    static $depth = 0;
    if ($depth > 0) {
        $depth++;
        try {
            return $fn();
        } finally {
            $depth--;
        }
    }
    rp_ensure_dirs();
    $lock = fopen(RP_DATA . '/.lock', 'c');
    flock($lock, LOCK_EX);
    $depth = 1;
    try {
        return $fn();
    } finally {
        $depth = 0;
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function rp_config_file(): string
{
    return rp_tenant_dir() . '/config.json';
}

function rp_stats_file(): string
{
    return rp_tenant_dir() . '/stats.json';
}

function rp_config(): array
{
    $file = rp_config_file();
    $cfg = rp_read_json($file);
    if ($cfg === null) {
        $cfg = rp_defaults();
        try {
            rp_locked(function () use ($cfg, $file) {
                if (!is_file($file)) {
                    rp_write_atomic($file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            });
            $cfg = rp_read_json($file, $cfg);
        } catch (RuntimeException) {
            // Sem permissão de escrita: segue com os padrões em memória.
        }
    }
    $def = rp_defaults();
    $cfg['settings'] = array_merge($def['settings'], $cfg['settings'] ?? []);
    foreach (['genres', 'stations', 'tvs', 'ads'] as $k) {
        $cfg[$k] = array_values($cfg[$k] ?? []);
    }
    return $cfg;
}

/** Lê, altera e grava a configuração de forma atômica. */
function rp_update_config(callable $mutate): array
{
    return rp_locked(function () use ($mutate) {
        $cfg = rp_config();
        $cfg = $mutate($cfg) ?? $cfg;
        $cfg['updated_at'] = time();
        rp_write_atomic(rp_config_file(), json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $cfg;
    });
}

/** Arquivos .php com "return [...]" — o conteúdo nunca é exibido mesmo se acessado pela web. */
function rp_read_php(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    $data = include $file;
    return is_array($data) ? $data : [];
}

function rp_write_php(string $file, array $data): void
{
    rp_write_atomic($file, "<?php\nreturn " . var_export($data, true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
}

// ---------- Estatísticas de publicidade ----------

function rp_track(string $adId, string $type): void
{
    if (!preg_match('/^[a-f0-9]{10}$/', $adId) || !in_array($type, ['view', 'click'], true)) {
        return;
    }
    rp_locked(function () use ($adId, $type) {
        $cfg = rp_read_json(rp_config_file(), ['ads' => []]);
        if (!in_array($adId, array_column($cfg['ads'] ?? [], 'id'), true)) {
            return;
        }
        $stats = rp_read_json(rp_stats_file(), []);
        $stats[$adId][$type . 's'] = ($stats[$adId][$type . 's'] ?? 0) + 1;
        rp_write_atomic(rp_stats_file(), json_encode($stats));
    });
}

// ---------- Publicidade ativa ----------

function rp_ad_is_live(array $ad, ?string $today = null): bool
{
    $today ??= date('Y-m-d');
    if (empty($ad['active'])) {
        return false;
    }
    if (!empty($ad['starts']) && $today < $ad['starts']) {
        return false;
    }
    if (!empty($ad['ends']) && $today > $ad['ends']) {
        return false;
    }
    return !empty($ad['image']) || trim((string) ($ad['title'] ?? '')) !== '';
}

// ---------- Interação: pedidos e chat ----------

function rp_tfile(string $name): string
{
    return rp_tenant_dir() . '/' . $name . '.json';
}

/** Identificador anônimo do aparelho do ouvinte (gerado no app). */
function rp_device(?string $d): string
{
    $d = strtolower(trim((string) $d));
    return preg_match('/^[a-f0-9]{16,40}$/', $d) ? $d : '';
}

function rp_ip_hash(): string
{
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . rp_tenant()), 0, 16);
}

/** Limite de frequência: false se a mesma chave agiu há menos de $seconds. */
function rp_rate_ok(string $key, int $seconds): bool
{
    return rp_locked(function () use ($key, $seconds) {
        $now = time();
        $all = array_filter(rp_read_json(rp_tfile('ratelimit'), []), fn ($t) => $t > $now - 3600);
        if (isset($all[$key]) && $all[$key] > $now - $seconds) {
            return false;
        }
        $all[$key] = $now;
        rp_write_atomic(rp_tfile('ratelimit'), json_encode($all));
        return true;
    });
}

/** Limite por volume: false se a chave já agiu $max vezes nos últimos $window segundos. */
function rp_rate_count(string $key, int $max, int $window): bool
{
    return rp_locked(function () use ($key, $max, $window) {
        $now = time();
        $file = rp_tfile('ratecount');
        $all = rp_read_json($file, []);
        foreach ($all as $k => $hits) {
            $all[$k] = array_values(array_filter($hits, fn ($t) => $t > $now - 3600));
            if (!$all[$k]) {
                unset($all[$k]);
            }
        }
        $recent = array_filter($all[$key] ?? [], fn ($t) => $t > $now - $window);
        if (count($recent) >= $max) {
            return false;
        }
        $all[$key][] = $now;
        rp_write_atomic($file, json_encode($all));
        return true;
    });
}

function rp_is_banned(string $device): bool
{
    $bans = rp_read_json(rp_tfile('bans'), []);
    return ($device !== '' && isset($bans['d:' . $device])) || isset($bans['i:' . rp_ip_hash()]);
}

/** Adiciona uma mensagem ao chat (mantém as últimas 300). */
function rp_chat_add(array $msg): array
{
    return rp_locked(function () use ($msg) {
        $chat = rp_read_json(rp_tfile('chat'), ['last' => 0, 'messages' => []]);
        $msg['id'] = ++$chat['last'];
        $msg['at'] = time();
        $chat['messages'][] = $msg;
        $chat['messages'] = array_slice($chat['messages'], -300);
        rp_write_atomic(rp_tfile('chat'), json_encode($chat, JSON_UNESCAPED_UNICODE));
        return $msg;
    });
}

/** Mensagens visíveis do chat a partir de um id (sem dados internos de quem enviou). */
function rp_chat_since(int $since, bool $admin = false, string $viewer = ''): array
{
    $chat = rp_read_json(rp_tfile('chat'), ['last' => 0, 'messages' => []]);
    $out = [];
    foreach ($chat['messages'] as $m) {
        if ($m['id'] <= $since) {
            continue;
        }
        $row = ['id' => $m['id'], 'nick' => $m['nick'], 'text' => $m['text'], 'at' => $m['at'], 'kind' => $m['kind'] ?? 'user'];
        if ($admin) {
            $row['device'] = $m['device'] ?? '';
        } else {
            $row['mine'] = $viewer !== '' && ($m['device'] ?? '') === $viewer;
        }
        $out[] = $row;
    }
    return ['last' => $chat['last'], 'messages' => $out, 'deleted' => $chat['deleted'] ?? []];
}

/** Ouvintes vistos no chat nos últimos 2 minutos. */
function rp_presence(?string $device = null, ?string $nick = null): array
{
    $file = rp_tfile('presence');
    $now = time();
    $all = array_filter(rp_read_json($file, []), fn ($p) => $p[1] > $now - 120);
    if ($device && $nick !== null && (!isset($all[$device]) || $all[$device][1] < $now - 25 || $all[$device][0] !== $nick)) {
        rp_locked(function () use ($file, $device, $nick, $now, &$all) {
            $all = array_filter(rp_read_json($file, []), fn ($p) => $p[1] > $now - 120);
            $all[$device] = [$nick, $now];
            rp_write_atomic($file, json_encode($all, JSON_UNESCAPED_UNICODE));
        });
    }
    return array_values(array_unique(array_map(fn ($p) => $p[0], $all)));
}

// ---------- Web TV ----------

const TV_TYPES = [
    'auto' => 'Detectar automaticamente',
    'hls' => 'Transmissão HLS (.m3u8)',
    'video' => 'Arquivo de vídeo (MP4/WebM)',
    'youtube' => 'YouTube',
    'embed' => 'Player incorporado (iframe)',
];

/**
 * Interpreta o link (ou código <iframe>) de um canal de TV.
 * Retorna [tipo, endereço que o app usa] ou lança erro explicando o que corrigir.
 */
function rp_parse_tv(string $input, string $type = 'auto'): array
{
    $input = trim($input);
    if (stripos($input, '<iframe') !== false) {
        if (!preg_match('~\ssrc\s*=\s*["\']([^"\']+)["\']~i', $input, $m)) {
            throw new RuntimeException('Não encontrei o endereço (src) dentro do código do iframe.');
        }
        $input = html_entity_decode($m[1], ENT_QUOTES);
        if ($type === 'auto') {
            $type = 'embed';
        }
    }
    if (str_starts_with($input, '//')) {
        $input = 'https:' . $input;
    }
    if (!preg_match('~^https?://~i', $input) || !filter_var($input, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Cole um link começando com https:// ou o código de incorporação (iframe).');
    }
    if (stripos($input, 'http://') === 0) {
        throw new RuntimeException('O link precisa ser https:// (o navegador bloqueia vídeo http dentro de um app seguro). Peça ao seu provedor o link https.');
    }
    $url = $input;
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    // YouTube: vídeo, live, shorts, embed ou "ao vivo" de um canal.
    if (preg_match('~(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$~', $host) && in_array($type, ['auto', 'youtube', 'embed'], true)) {
        $id = null;
        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif (!empty($q['v'])) {
            $id = $q['v'];
        } elseif (preg_match('~^/(live|embed|shorts)/([A-Za-z0-9_-]{6,})~', $path, $m) && $m[2] !== 'live_stream') {
            $id = $m[2];
        } elseif (preg_match('~^/channel/(UC[A-Za-z0-9_-]+)~', $path, $m)) {
            return ['youtube', 'https://www.youtube.com/embed/live_stream?channel=' . $m[1] . '&autoplay=1&playsinline=1'];
        } elseif (!empty($q['channel'])) {
            return ['youtube', 'https://www.youtube.com/embed/live_stream?channel=' . rawurlencode($q['channel']) . '&autoplay=1&playsinline=1'];
        }
        if ($id && preg_match('~^[A-Za-z0-9_-]{6,20}$~', $id)) {
            return ['youtube', 'https://www.youtube.com/embed/' . $id . '?autoplay=1&playsinline=1&rel=0'];
        }
        throw new RuntimeException('Link do YouTube não reconhecido. Use o link do vídeo/live (youtube.com/watch?v=… ou youtu.be/…) ou o do canal (youtube.com/channel/UC…).');
    }
    // Vimeo
    if (preg_match('~(^|\.)vimeo\.com$~', $host) && $host !== 'player.vimeo.com' && preg_match('~/(\d+)~', $path, $m)) {
        return ['embed', 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1'];
    }
    if ($type === 'auto') {
        $type = match (true) {
            (bool) preg_match('~\.m3u8$~i', $path) => 'hls',
            (bool) preg_match('~\.(mp4|webm|ogv|mov)$~i', $path) => 'video',
            default => 'embed',
        };
    }
    if (!isset(TV_TYPES[$type]) || $type === 'auto') {
        $type = 'embed';
    }
    return [$type, mb_substr($url, 0, 1000)];
}

// ---------- Validação ----------

function rp_clean(?string $s, int $max = 200): string
{
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $s) ?? '');
    return mb_substr($s, 0, $max);
}

function rp_clean_text(?string $s, int $max = 1000): string
{
    $s = str_replace("\r\n", "\n", (string) $s);
    $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** Aceita apenas URLs http(s) — bloqueia javascript:, data: etc. */
function rp_url(?string $s): string
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $s)) {
        $s = 'https://' . ltrim($s, '/');
    }
    return filter_var($s, FILTER_VALIDATE_URL) && preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $s) ? mb_substr($s, 0, 500) : '';
}

function rp_color(?string $s, string $fallback = '#e5484d'): string
{
    $s = trim((string) $s);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $s) ? strtolower($s) : $fallback;
}

function rp_date(?string $s): string
{
    $s = trim((string) $s);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s ? $s : '';
}

/** Converte "(62) 99999-0000" em link wa.me; mantém links já completos. */
function rp_whatsapp_link(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $s)) {
        return rp_url($s);
    }
    $digits = preg_replace('/\D/', '', $s);
    if (strlen($digits) >= 10 && strlen($digits) <= 11) {
        $digits = '55' . $digits;
    }
    return strlen($digits) >= 10 ? 'https://wa.me/' . $digits : '';
}

function rp_social_link(string $s, string $base): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $s) || str_contains($s, '/')) {
        return rp_url($s);
    }
    return $base . rawurlencode(ltrim($s, '@'));
}

// ---------- Uploads de imagem ----------

/**
 * Valida e salva uma imagem enviada. As imagens (exceto GIF animado) são
 * recodificadas com GD, o que descarta qualquer conteúdo escondido no arquivo.
 * Retorna o caminho relativo ("uploads/<cliente>/xxxx.jpg") ou '' se nada foi enviado.
 */
function rp_save_upload(string $field, int $maxSide = 1600): string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Imagem muito grande (máximo 4 MB).' : 'Falha no envio da imagem.');
    }
    if ($f['size'] > RP_MAX_UPLOAD) {
        throw new RuntimeException('Imagem muito grande (máximo 4 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($types[$mime]) || !@getimagesize($f['tmp_name'])) {
        throw new RuntimeException('Formato não aceito. Envie JPG, PNG, WEBP ou GIF.');
    }
    rp_ensure_dirs();
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8));

    if ($mime === 'image/gif' || !extension_loaded('gd')) {
        $dest = $name . '.' . $types[$mime];
        if (!move_uploaded_file($f['tmp_name'], rp_upload_dir() . '/' . $dest)) {
            throw new RuntimeException('Não foi possível salvar a imagem. Verifique as permissões da pasta uploads/.');
        }
        @chmod(rp_upload_dir() . '/' . $dest, 0664);
        return 'uploads/' . rp_tenant() . '/' . $dest;
    }

    $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
    if (!$src) {
        throw new RuntimeException('Não foi possível ler a imagem.');
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $keepAlpha = $mime !== 'image/jpeg';
    if ($keepAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dest = $name . ($keepAlpha ? '.png' : '.jpg');
    $ok = $keepAlpha ? imagepng($dst, rp_upload_dir() . '/' . $dest, 8) : imagejpeg($dst, rp_upload_dir() . '/' . $dest, 86);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        throw new RuntimeException('Não foi possível salvar a imagem. Verifique as permissões da pasta uploads/.');
    }
    @chmod(rp_upload_dir() . '/' . $dest, 0664);
    return 'uploads/' . rp_tenant() . '/' . $dest;
}

/** Apaga um arquivo enviado (só dentro de uploads/<cliente atual>/). */
function rp_delete_upload(?string $path): void
{
    if ($path && preg_match('~^uploads/' . preg_quote(rp_tenant(), '~') . '/[A-Za-z0-9._-]+$~', $path) && is_file(RP_ROOT . '/' . $path)) {
        @unlink(RP_ROOT . '/' . $path);
    }
}

/** Gera os ícones do aplicativo (192, 512 e maskable) a partir do logo enviado. */
function rp_make_icons(string $logo, string $bg): bool
{
    if (!extension_loaded('gd') || !is_file(RP_ROOT . '/' . $logo)) {
        return false;
    }
    $src = @imagecreatefromstring((string) file_get_contents(RP_ROOT . '/' . $logo));
    if (!$src) {
        return false;
    }
    [$r, $g, $b] = sscanf($bg, '#%02x%02x%02x');
    foreach (['icon-192.png' => [192, 0], 'icon-512.png' => [512, 0], 'maskable-512.png' => [512, 0.12]] as $file => [$size, $pad]) {
        $im = imagecreatetruecolor($size, $size);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        imagealphablending($im, true);
        $box = $size * (1 - 2 * $pad);
        $scale = min($box / imagesx($src), $box / imagesy($src));
        $w = (int) round(imagesx($src) * $scale);
        $h = (int) round(imagesy($src) * $scale);
        imagecopyresampled($im, $src, (int) (($size - $w) / 2), (int) (($size - $h) / 2), 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagepng($im, rp_upload_dir() . '/app-' . $file);
        imagedestroy($im);
    }
    imagedestroy($src);
    return true;
}

function rp_icon(string $file): string
{
    $path = rp_upload_dir() . '/app-' . $file;
    return is_file($path) ? 'uploads/' . rp_tenant() . '/app-' . $file . '?v=' . filemtime($path) : 'icons/' . $file;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

rp_migrate_single();
