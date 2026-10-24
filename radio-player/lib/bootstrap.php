<?php
declare(strict_types=1);

/*
 * Núcleo do Rádio Player: armazenamento em arquivos (sem banco de dados),
 * uploads de imagens e helpers usados pela API e pelo painel.
 */

date_default_timezone_set('America/Sao_Paulo');

const RP_ROOT = __DIR__ . '/..';
const RP_DATA = RP_ROOT . '/data';
const RP_UPLOADS = RP_ROOT . '/uploads';
const RP_CONFIG = RP_DATA . '/config.json';
const RP_STATS = RP_DATA . '/stats.json';
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
        ],
        'genres' => $genres,
        'stations' => [],
        'ads' => [],
        'updated_at' => time(),
    ];
}

function rp_id(): string
{
    return bin2hex(random_bytes(5));
}

// ---------- Armazenamento ----------

function rp_ensure_dirs(): void
{
    foreach ([RP_DATA, RP_UPLOADS] as $dir) {
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

/** Executa $fn com um lock exclusivo (evita que dois salvamentos simultâneos se percam). */
function rp_locked(callable $fn)
{
    rp_ensure_dirs();
    $lock = fopen(RP_DATA . '/.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function rp_config(): array
{
    $cfg = rp_read_json(RP_CONFIG);
    if ($cfg === null) {
        $cfg = rp_defaults();
        try {
            rp_locked(function () use ($cfg) {
                if (!is_file(RP_CONFIG)) {
                    rp_write_atomic(RP_CONFIG, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            });
            $cfg = rp_read_json(RP_CONFIG, $cfg);
        } catch (RuntimeException) {
            // Sem permissão de escrita: segue com os padrões em memória.
        }
    }
    $def = rp_defaults();
    $cfg['settings'] = array_merge($def['settings'], $cfg['settings'] ?? []);
    foreach (['genres', 'stations', 'ads'] as $k) {
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
        rp_write_atomic(RP_CONFIG, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        $cfg = rp_read_json(RP_CONFIG, ['ads' => []]);
        if (!in_array($adId, array_column($cfg['ads'] ?? [], 'id'), true)) {
            return;
        }
        $stats = rp_read_json(RP_STATS, []);
        $stats[$adId][$type . 's'] = ($stats[$adId][$type . 's'] ?? 0) + 1;
        rp_write_atomic(RP_STATS, json_encode($stats));
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
 * Retorna o caminho relativo ("uploads/xxxx.jpg") ou '' se nada foi enviado.
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
        if (!move_uploaded_file($f['tmp_name'], RP_UPLOADS . '/' . $dest)) {
            throw new RuntimeException('Não foi possível salvar a imagem. Verifique as permissões da pasta uploads/.');
        }
        @chmod(RP_UPLOADS . '/' . $dest, 0664);
        return 'uploads/' . $dest;
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
    $ok = $keepAlpha ? imagepng($dst, RP_UPLOADS . '/' . $dest, 8) : imagejpeg($dst, RP_UPLOADS . '/' . $dest, 86);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        throw new RuntimeException('Não foi possível salvar a imagem. Verifique as permissões da pasta uploads/.');
    }
    @chmod(RP_UPLOADS . '/' . $dest, 0664);
    return 'uploads/' . $dest;
}

/** Apaga um arquivo enviado (só dentro de uploads/). */
function rp_delete_upload(?string $path): void
{
    if ($path && preg_match('~^uploads/[A-Za-z0-9._-]+$~', $path) && is_file(RP_ROOT . '/' . $path)) {
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
        imagepng($im, RP_UPLOADS . '/app-' . $file);
        imagedestroy($im);
    }
    imagedestroy($src);
    return true;
}

function rp_icon(string $file): string
{
    return is_file(RP_UPLOADS . '/app-' . $file) ? 'uploads/app-' . $file . '?v=' . filemtime(RP_UPLOADS . '/app-' . $file) : 'icons/' . $file;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
