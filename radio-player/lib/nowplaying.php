<?php
declare(strict_types=1);

/*
 * "RDS" da web rádio: descobre a música tocando a partir do streaming.
 *
 * 1. Metadados ICY dentro do próprio áudio (Shoutcast e Icecast enviam o StreamTitle
 *    a cada N bytes quando o pedido tem "Icy-MetaData: 1").
 * 2. Se não houver, tenta as páginas de status do servidor (Icecast status-json.xsl,
 *    Shoutcast v2 /stats e v1 /7.html) ou a URL de metadados cadastrada no painel.
 * 3. Com artista e música, busca a capa do álbum no iTunes (sem chave de API).
 *
 * Tudo é feito no servidor (sem depender de CORS do provedor) e guardado em cache
 * por 15 s, então muitos ouvintes geram uma única consulta ao streaming.
 */

const NP_TTL = 10;          // segundos de cache da música tocando
const NP_FAIL_TTL = 60;     // quando não há metadados, espera mais para tentar de novo
const NP_TIMEOUT = 5;       // segundos por conexão
const NP_PUSH_TTL = 600;    // música enviada pelo computador da rádio vale por 10 min sem novo envio

/** Resolve o host e devolve um IP público — bloqueia rede interna (127.x, 10.x, 192.168.x…). */
function np_public_ip(string $host): ?string
{
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        return null;
    }
    if (getenv('RP_NP_ALLOW_PRIVATE') === '1') {
        return $ips[0]; // só para testes locais
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }
    return $ips[0];
}

/** Corrige texto que chega em Latin-1 (comum em Shoutcast antigo). */
function np_utf8(string $s): string
{
    $s = trim($s);
    if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Lê o StreamTitle de dentro do streaming (metadados ICY). */
function np_icy_title(string $url, int $redirects = 2): ?string
{
    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = $p['host'] ?? '';
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return null;
    }
    $ip = np_public_ip($host);
    if (!$ip) {
        return null;
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
    // Conecta no IP já verificado (evita trocar o DNS entre a checagem e a conexão).
    $fp = @stream_socket_client(($scheme === 'https' ? 'ssl://' : 'tcp://') . $ip . ':' . $port, $errno, $err, NP_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return null;
    }
    stream_set_timeout($fp, NP_TIMEOUT);
    $deadline = microtime(true) + 8;
    $hostHeader = $host . (isset($p['port']) ? ':' . $port : '');
    fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: {$hostHeader}\r\nIcy-MetaData: 1\r\nUser-Agent: Mozilla/5.0 (RadioPlayer RDS)\r\nAccept: */*\r\nConnection: close\r\n\r\n");

    $status = (string) fgets($fp, 1024);
    $headers = [];
    for ($i = 0; $i < 80 && !feof($fp); $i++) {
        $line = rtrim((string) fgets($fp, 4096), "\r\n");
        if ($line === '') {
            break;
        }
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    if (preg_match('~^\S+\s+30[1278]~', $status) && !empty($headers['location']) && $redirects > 0) {
        fclose($fp);
        $loc = $headers['location'];
        if (str_starts_with($loc, '/')) {
            $loc = $scheme . '://' . $hostHeader . $loc;
        }
        return np_icy_title($loc, $redirects - 1);
    }
    $metaint = (int) ($headers['icy-metaint'] ?? 0);
    if ($metaint <= 0 || $metaint > 512000) {
        fclose($fp);
        return null;
    }
    $readExact = function (int $n) use ($fp, $deadline): ?string {
        $buf = '';
        while (strlen($buf) < $n && !feof($fp) && microtime(true) < $deadline) {
            $chunk = fread($fp, min(8192, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($fp)['timed_out']) {
                    return null;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return strlen($buf) === $n ? $buf : null;
    };
    $title = null;
    // O primeiro bloco às vezes vem vazio: olha até 3 blocos.
    for ($block = 0; $block < 3 && $title === null; $block++) {
        if ($readExact($metaint) === null || ($lenByte = $readExact(1)) === null) {
            break;
        }
        $len = ord($lenByte) * 16;
        if ($len === 0) {
            continue;
        }
        $meta = $readExact($len);
        if ($meta !== null && preg_match("~StreamTitle='(.*?)';~s", $meta, $m)) {
            $title = np_utf8($m[1]);
        }
    }
    fclose($fp);
    return $title;
}

/** Busca uma URL pública (status do servidor de streaming ou iTunes). */
function np_http_get(string $url, int $max = 262144): ?string
{
    $p = parse_url($url);
    $host = $p['host'] ?? '';
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || !($ip = np_public_ip($host)) || !function_exists('curl_init')) {
        return null;
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => NP_TIMEOUT,
        CURLOPT_TIMEOUT => NP_TIMEOUT + 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (RadioPlayer RDS)',
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buf, $max) {
            $buf .= $data;
            return strlen($buf) > $max ? 0 : strlen($data);
        },
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300 && $buf !== '' ? $buf : null;
}

/** Extrai o título de páginas de status (Icecast JSON, Shoutcast v2 JSON/XML, Shoutcast v1 7.html, JSON simples). */
function np_parse_status(string $body, string $stream): ?string
{
    $json = json_decode($body, true);
    if (is_array($json)) {
        if (isset($json['icestats'])) {
            $src = $json['icestats']['source'] ?? null;
            if (is_array($src) && array_is_list($src)) {
                $path = (string) parse_url($stream, PHP_URL_PATH);
                $pick = null;
                foreach ($src as $s) {
                    if ($path !== '' && str_ends_with((string) ($s['listenurl'] ?? ''), $path)) {
                        $pick = $s;
                    }
                }
                $src = $pick ?? $src[0] ?? null;
            }
            if (!is_array($src)) {
                return null;
            }
            $t = $src['title'] ?? '';
            if (!empty($src['artist']) && $t !== '' && !str_contains($t, ' - ')) {
                $t = $src['artist'] . ' - ' . $t;
            }
            return $t !== '' ? np_utf8((string) $t) : null;
        }
        $d = $json['now_playing']['song'] ?? $json['nowplaying'] ?? $json['current'] ?? $json;
        if (!empty($d['songtitle'])) {
            return np_utf8((string) $d['songtitle']);
        }
        if (!empty($d['text'])) {
            return np_utf8((string) $d['text']);
        }
        $t = trim(($d['artist'] ?? '') . ' - ' . ($d['title'] ?? ''), ' -');
        return $t !== '' ? np_utf8($t) : null;
    }
    if (preg_match('~<SONGTITLE[^>]*>(.*?)</SONGTITLE>~is', $body, $m)) {
        return np_utf8(html_entity_decode($m[1], ENT_QUOTES));
    }
    // Shoutcast v1 /7.html: <body>ouvintes,status,pico,max,unicos,bitrate,MÚSICA</body>
    $text = trim(strip_tags($body));
    if (preg_match('~^\d+,\d+,\d+,\d+,\d+,\d+,(.+)$~s', $text, $m)) {
        return np_utf8(html_entity_decode($m[1], ENT_QUOTES));
    }
    return null;
}

/** Descobre a música tocando no streaming de uma rádio. */
function np_detect(string $stream, string $metaUrl = ''): ?string
{
    if ($metaUrl !== '') {
        $body = np_http_get($metaUrl);
        return $body !== null ? np_parse_status($body, $stream) : null;
    }
    if (($t = np_icy_title($stream)) !== null && $t !== '') {
        return $t;
    }
    $p = parse_url($stream);
    $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    foreach (['/status-json.xsl', '/stats?sid=1&json=1', '/7.html'] as $try) {
        $body = np_http_get($base . $try);
        if ($body !== null && ($t = np_parse_status($body, $stream))) {
            return $t;
        }
    }
    return null;
}

/** "Artista - Música" → [artista, música]. */
function np_split(string $song): array
{
    $parts = preg_split('~\s+[-–—]\s+~u', $song, 2);
    return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($song)];
}

/** Capa do álbum pelo iTunes, com cache (inclusive de "não encontrado"). */
function np_artwork(string $artist, string $title): string
{
    $key = mb_strtolower($artist . '|' . $title);
    $file = rp_tfile('art-cache');
    $cache = rp_read_json($file, []);
    if (array_key_exists($key, $cache)) {
        return (string) $cache[$key];
    }
    $art = '';
    // Busca sem o que está entre parênteses/colchetes ("(15 Anos)", "(Ao Vivo)") para achar mais capas.
    $term = trim((string) preg_replace('~\s*[\[(][^\])]*[\])]~u', '', $artist . ' ' . $title));
    if (mb_strlen($term) >= 3) {
        $body = np_http_get('https://itunes.apple.com/search?' . http_build_query(['term' => $term, 'media' => 'music', 'entity' => 'song', 'limit' => 1, 'country' => 'BR']));
        $res = $body ? json_decode($body, true) : null;
        $url = $res['results'][0]['artworkUrl100'] ?? '';
        if (is_string($url) && preg_match('~^https://[a-z0-9.-]+\.mzstatic\.com/~i', $url)) {
            $art = str_replace('100x100bb', '600x600bb', $url);
        }
    }
    rp_locked(function () use ($file, $key, $art) {
        $cache = rp_read_json($file, []);
        $cache[$key] = $art;
        if (count($cache) > 400) {
            $cache = array_slice($cache, -300, null, true);
        }
        rp_write_atomic($file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    });
    return $art;
}

/**
 * Música tocando numa rádio do cliente atual (com cache).
 * Retorna ['song', 'artist', 'title', 'art', 'at', 'history'].
 */
function np_now(array $station, bool $withArt, bool $force = false): array
{
    $id = (string) $station['id'];
    $file = rp_tfile('np');
    $all = rp_read_json($file, []);
    $cur = $all[$id] ?? ['song' => '', 'artist' => '', 'title' => '', 'art' => '', 'at' => 0, 'checked' => 0, 'history' => []];
    // Enviado pelo computador da rádio (script do Zara): tem prioridade enquanto estiver ativo.
    if (time() - (int) ($cur['pushed_at'] ?? 0) < NP_PUSH_TTL) {
        return $cur;
    }
    $ttl = $cur['song'] !== '' ? NP_TTL : NP_FAIL_TTL;
    if (!$force && time() - (int) $cur['checked'] < $ttl) {
        return $cur;
    }
    // Só uma requisição por vez consulta o streaming; as outras usam o que já está no cache.
    $lock = fopen(rp_tenant_dir() . '/.np-' . preg_replace('/[^a-z0-9]/', '', $id) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return $cur;
    }
    try {
        $song = np_tidy(np_detect((string) $station['stream'], (string) ($station['metadata'] ?? '')) ?? '');
        // Alguns servidores mandam lixo ou só o nome da rádio quando não há música.
        if (mb_strlen($song) < 2 || preg_match('~^(unknown|desconhecido|-|\s*)$~i', $song)) {
            $song = '';
        }
        $next = $cur;
        $next['checked'] = time();
        if ($song !== $cur['song']) {
            [$artist, $title] = $song !== '' ? np_split($song) : ['', ''];
            $next['song'] = $song;
            $next['artist'] = $artist;
            $next['title'] = $title;
            $next['at'] = time();
            $next['art'] = '';
            if ($song !== '') {
                array_unshift($next['history'], ['song' => $song, 'at' => time()]);
                $next['history'] = array_slice($next['history'], 0, 10);
            }
        }
        if ($withArt && $next['song'] !== '' && $next['art'] === '' && $song !== $cur['song']) {
            $next['art'] = np_artwork($next['artist'], $next['title']);
        }
        rp_locked(function () use ($file, $id, $next) {
            $all = rp_read_json($file, []);
            $all[$id] = $next;
            rp_write_atomic($file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });
        return $next;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Limpa o que o programa de automação grava ("C:\\Musicas\\Artista - Musica.mp3" → "Artista - Musica"). */
function np_clean_pushed(string $song): string
{
    $lines = array_values(array_filter(array_map('np_utf8', preg_split('~\r\n|\r|\n~', str_replace("\0", '', $song))), 'strlen'));
    $song = $lines[0] ?? '';
    // Duas linhas curtas sem " - " (ex.: artista numa linha, música na outra) viram "artista - música".
    if (count($lines) >= 2 && !str_contains($lines[0], ' - ') && !str_contains($lines[1], ' - ')
        && mb_strlen($lines[0]) <= 60 && mb_strlen($lines[1]) <= 80 && !preg_match('~[\\\\/]~', $lines[0])) {
        $song = $lines[0] . ' - ' . $lines[1];
    }
    if (preg_match('~[\\\\/]~', $song)) {
        $song = (string) preg_replace('~^.*[\\\\/]~', '', $song);
    }
    $song = (string) preg_replace('~\.(mp3|wav|wma|ogg|flac|aac|m4a|mp2)$~i', '', $song);
    $song = trim((string) preg_replace('~^\d{1,4}\s*[-._)]\s*~u', '', $song)); // "01 - Música", "168 - Música"
    return np_tidy($song);
}

/** Tira sufixos de vídeo ("[Vídeo Oficial]", "(Clipe Oficial)", "| Lyric") e ignora vinhetas/comerciais. */
function np_tidy(string $song): string
{
    $junk = '(v[ií]deo|video|clipe?|clip|official|oficial|lyrics?|letra|legendad[oa]|visualizer|audio|áudio|hd|hq|4k|1080p|720p|mv)';
    $song = (string) preg_replace('~\s*[\[(][^\])]*\b' . $junk . '\b[^\])]*[\])]~iu', '', $song);
    $song = (string) preg_replace('~\s*[|｜]\s*.*$~u', '', $song);
    $song = (string) preg_replace('~\s+(hd|hq|4k|1080p|720p)$~iu', '', $song);
    $song = trim((string) preg_replace('~\s{2,}~u', ' ', $song), " \t-–—_");
    // Vinheta, spot, comercial, hora certa: não é música.
    if ($song === '' || preg_match('~^(vinhetas?|spots?|comerciais?|comercial|propagandas?|hora certa|chamadas?|jingles?|id|intervalo)\b~iu', $song)) {
        return '';
    }
    return mb_substr($song, 0, 200);
}

/** Grava a música enviada pelo computador da rádio. */
function np_push(array $station, string $song, bool $withArt): array
{
    $id = (string) $station['id'];
    $file = rp_tfile('np');
    $cur = rp_read_json($file, [])[$id] ?? ['song' => '', 'artist' => '', 'title' => '', 'art' => '', 'at' => 0, 'checked' => 0, 'history' => []];
    $next = $cur;
    $next['pushed_at'] = time();
    $next['checked'] = time();
    if ($song !== $cur['song']) {
        [$artist, $title] = $song !== '' ? np_split($song) : ['', ''];
        $next = array_merge($next, ['song' => $song, 'artist' => $artist, 'title' => $title, 'at' => time(), 'art' => '']);
        if ($song !== '') {
            array_unshift($next['history'], ['song' => $song, 'at' => time()]);
            $next['history'] = array_slice($next['history'], 0, 10);
            if ($withArt) {
                $next['art'] = np_artwork($artist, $title);
            }
        }
    }
    rp_locked(function () use ($file, $id, $next) {
        $all = rp_read_json($file, []);
        $all[$id] = $next;
        rp_write_atomic($file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    });
    return $next;
}
