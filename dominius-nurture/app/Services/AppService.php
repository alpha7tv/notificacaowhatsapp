<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;

/**
 * Aplicativo Dominius Play: clientes (código de ativação + lista Xtream), aparelhos, avisos/publicidade,
 * configurações e versões do APK. A API pública (AppApiController) e o painel usam este serviço.
 */
final class AppService
{
    public const NOTICE_KINDS = ['info' => 'Aviso', 'ad' => 'Publicidade'];
    public const PLACEMENTS = ['home' => 'Tela inicial', 'popup' => 'Janela ao abrir o app', 'login' => 'Tela de ativação'];
    public const AUDIENCES = ['all' => 'Todos', 'trial' => 'Só testes', 'paid' => 'Só clientes pagantes'];

    // ------------------------------------------------------------------ configuração

    public static function cfg(string $key, string $default = ''): string
    {
        try {
            $v = Database::value('SELECT v FROM app_config WHERE k = :k', ['k' => $key]);
            return $v === null || $v === false ? $default : (string) $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function setCfg(string $key, string $value): void
    {
        Database::query(
            'INSERT INTO app_config (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            ['k' => $key, 'v' => $value]
        );
    }

    /** Padrões e rótulos das configurações editáveis no painel. */
    public static function cfgFields(): array
    {
        return [
            'app_name' => ['Nome do aplicativo', 'Dominius Play'],
            'support_whatsapp' => ['WhatsApp de suporte (com 55 e DDD)', preg_replace('/\D+/', '', (string) Env::get('NURTURE_WHATSAPP', '5514988159045')) ?: '5514988159045'],
            'default_server' => ['Servidor (DNS) dos clientes, cadastrado uma única vez (ex.: http://seudns.com:80)', ''],
            'expired_title' => ['Título quando o acesso vence', 'Seu acesso venceu'],
            'expired_message' => ['Mensagem quando o acesso vence', 'Fale com a gente pelo WhatsApp para renovar e voltar a assistir.'],
            'downloader_code' => ['Código do Downloader (opcional, para TV)', ''],
            'max_devices' => ['Aparelhos por cliente (padrão)', '2'],
        ];
    }

    // ------------------------------------------------------------------ códigos e clientes

    public static function newCode(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = (string) random_int(10000000, 99999999);
            if ((int) Database::value('SELECT COUNT(*) FROM app_clients WHERE code = :c', ['c' => $code]) === 0) {
                return $code;
            }
        }
        throw new \RuntimeException('Não foi possível gerar um código único.');
    }

    public static function createClient(array $d): int
    {
        return Database::insert('app_clients', [
            'name' => mb_substr(trim((string) ($d['name'] ?? 'Cliente')), 0, 150) ?: 'Cliente',
            'email' => ($d['email'] ?? '') !== '' ? mb_substr((string) $d['email'], 0, 190) : null,
            'phone' => ($d['phone'] ?? '') !== '' ? mb_substr((string) $d['phone'], 0, 30) : null,
            'code' => $d['code'] ?? self::newCode(),
            'server_url' => ($d['server_url'] ?? '') !== '' ? self::cleanServer((string) $d['server_url']) : null,
            'username' => ($d['username'] ?? '') !== '' ? mb_substr((string) $d['username'], 0, 120) : null,
            'password_enc' => ($d['password'] ?? '') !== '' ? Crypto::encrypt((string) $d['password']) : null,
            'expires_at' => $d['expires_at'] ?? null,
            'is_trial' => !empty($d['is_trial']) ? 1 : 0,
            'status' => 'active',
            'max_devices' => max(1, min(10, (int) ($d['max_devices'] ?? (int) self::cfg('max_devices', '2')))),
            'test_request_id' => $d['test_request_id'] ?? null,
            'notes' => ($d['notes'] ?? '') !== '' ? (string) $d['notes'] : null,
            'created_at' => Database::now(),
        ]);
    }

    public static function cleanServer(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }
        return rtrim($url, '/');
    }

    /**
     * Chamado quando um teste é gerado: cria (uma vez) o cliente do app e devolve o código para o e-mail.
     * Só devolve algo para o e-mail quando já existe um APK publicado.
     * @return array{code:string,url:string,downloader:string}|null
     */
    public static function forTest(int $testRequestId, array $result): ?array
    {
        try {
            $client = Database::fetch('SELECT * FROM app_clients WHERE test_request_id = :t', ['t' => $testRequestId]);
            if (!$client) {
                $user = trim((string) ($result['username'] ?? ''));
                $pass = (string) ($result['password'] ?? '');
                if ($user === '' || $pass === '') {
                    return null;
                }
                $req = Database::fetch('SELECT name, email, phone_e164 FROM test_requests WHERE id = :id', ['id' => $testRequestId]);
                if (!$req) {
                    return null;
                }
                $id = self::createClient([
                    'name' => $req['name'],
                    'email' => $req['email'],
                    'phone' => $req['phone_e164'],
                    'server_url' => (string) ($result['server'] ?? ''),
                    'username' => $user,
                    'password' => $pass,
                    'expires_at' => self::parseExpiry((string) ($result['expires'] ?? '')),
                    'is_trial' => 1,
                    'test_request_id' => $testRequestId,
                    'max_devices' => 2,
                ]);
                $client = Database::fetch('SELECT * FROM app_clients WHERE id = :id', ['id' => $id]);
            }
            if (!$client || !self::latestVersion()) {
                return null;
            }
            return ['code' => (string) $client['code'], 'url' => url('app'), 'downloader' => self::cfg('downloader_code', '')];
        } catch (\Throwable $e) {
            Logger::error('App: falha ao criar o cliente do teste', ['e' => $e->getMessage()]);
            return null;
        }
    }

    /** "08/10/2026 02:42:23" (horário de Brasília) → UTC; sem data válida, 4 horas a partir de agora. */
    public static function parseExpiry(string $s): string
    {
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?~', trim($s), $m)) {
            try {
                $d = new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:" . ($m[6] ?? '00'), new \DateTimeZone('America/Sao_Paulo'));
                return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
            }
        }
        return gmdate('Y-m-d H:i:s', time() + 4 * 3600);
    }

    // ------------------------------------------------------------------ API do aplicativo

    /** @return array{ok:bool,error?:string,message?:string,payload?:array} */
    public static function activate(string $code, string $deviceKey, string $model, string $appVersion, string $ip): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 8 || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $deviceKey)) {
            return ['ok' => false, 'error' => 'invalid', 'message' => 'Código inválido. Confira os 8 números.'];
        }
        $client = Database::fetch('SELECT * FROM app_clients WHERE code = :c', ['c' => $code]);
        if (!$client) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Código não encontrado. Confira os números ou fale com o suporte.'];
        }
        if ($client['status'] === 'blocked') {
            return ['ok' => false, 'error' => 'blocked', 'message' => 'Este acesso está bloqueado. Fale com o suporte.'];
        }
        return self::bindDevice($client, $deviceKey, $model, $appVersion, $ip);
    }

    /** Registra/atualiza o aparelho do cliente e devolve a sessão (token) para o app. */
    private static function bindDevice(array $client, string $deviceKey, string $model, string $appVersion, string $ip): array
    {
        $dev = Database::fetch('SELECT * FROM app_devices WHERE client_id = :c AND device_key = :d', ['c' => $client['id'], 'd' => $deviceKey]);
        if (!$dev) {
            $count = (int) Database::value('SELECT COUNT(*) FROM app_devices WHERE client_id = :c', ['c' => $client['id']]);
            if ($count >= (int) $client['max_devices']) {
                return ['ok' => false, 'error' => 'device_limit', 'message' => 'Limite de aparelhos atingido para este código. Fale com o suporte para liberar.'];
            }
        }
        $token = bin2hex(random_bytes(24));
        $fields = [
            'token_hash' => hash('sha256', $token),
            'model' => mb_substr($model, 0, 120),
            'app_version' => mb_substr($appVersion, 0, 20),
            'ip' => mb_substr($ip, 0, 45),
            'last_seen_at' => Database::now(),
        ];
        if ($dev) {
            Database::update('app_devices', $fields, 'id = :id', ['id' => $dev['id']]);
        } else {
            Database::insert('app_devices', $fields + ['client_id' => (int) $client['id'], 'device_key' => $deviceKey, 'first_seen_at' => Database::now()]);
        }
        Database::query('UPDATE app_clients SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $client['id']]);
        return ['ok' => true, 'payload' => self::payload($client) + ['token' => $token]];
    }

    /**
     * Entrada por usuário e senha (o servidor/DNS vem do painel, cadastrado uma única vez).
     * Se o usuário ainda não existe no painel, confere no servidor IPTV e cria o cliente automaticamente.
     */
    public static function login(string $user, string $pass, string $deviceKey, string $model, string $appVersion, string $ip): array
    {
        $user = trim($user);
        if ($user === '' || mb_strlen($user) > 120 || $pass === '' || mb_strlen($pass) > 200 || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $deviceKey)) {
            return ['ok' => false, 'error' => 'invalid', 'message' => 'Digite o usuário e a senha.'];
        }
        $rows = Database::fetchAll('SELECT * FROM app_clients WHERE username = :u', ['u' => $user]);
        $client = null;
        foreach ($rows as $r) {
            $stored = $r['password_enc'] ? (string) Crypto::decrypt($r['password_enc']) : '';
            if ($stored !== '' && hash_equals($stored, $pass)) {
                $client = $r;
                break;
            }
        }
        if (!$client) {
            $server = self::cleanServer(self::cfg('default_server', ''));
            if ($server === '') {
                return ['ok' => false, 'error' => 'no_server', 'message' => 'Servidor não configurado. Fale com o suporte.'];
            }
            $chk = self::xtreamCheck($server, $user, $pass);
            if (!$chk['ok']) {
                return ['ok' => false, 'error' => $chk['error'], 'message' => $chk['message']];
            }
            $exp = $chk['exp'] !== null ? gmdate('Y-m-d H:i:s', $chk['exp']) : null;
            if ($rows) {
                // cliente já cadastrado com este usuário, mas a senha mudou no servidor → atualiza
                $client = $rows[0];
                Database::update('app_clients', ['password_enc' => Crypto::encrypt($pass), 'expires_at' => $exp, 'updated_at' => Database::now()], 'id = :id', ['id' => $client['id']]);
                $client['expires_at'] = $exp;
            } else {
                $id = self::createClient([
                    'name' => $user,
                    'server_url' => $server,
                    'username' => $user,
                    'password' => $pass,
                    'expires_at' => $exp,
                    'is_trial' => $chk['trial'] ? 1 : 0,
                    'notes' => 'Criado automaticamente no primeiro acesso pelo app.',
                ]);
                $client = Database::fetch('SELECT * FROM app_clients WHERE id = :id', ['id' => $id]);
            }
        } elseif (($client['status'] ?? '') !== 'blocked' && $client['expires_at'] !== null && self::isExpired($client)) {
            // pode ter sido renovado no servidor: confere de novo
            $server = (string) ($client['server_url'] ?: self::cfg('default_server', ''));
            $chk = $server !== '' ? self::xtreamCheck(self::cleanServer($server), $user, $pass) : ['ok' => false];
            if (!empty($chk['ok']) && $chk['exp'] !== null && $chk['exp'] > time()) {
                $exp = gmdate('Y-m-d H:i:s', $chk['exp']);
                Database::update('app_clients', ['expires_at' => $exp, 'updated_at' => Database::now()], 'id = :id', ['id' => $client['id']]);
                $client['expires_at'] = $exp;
            }
        }
        if (($client['status'] ?? '') === 'blocked') {
            return ['ok' => false, 'error' => 'blocked', 'message' => 'Este acesso está bloqueado. Fale com o suporte.'];
        }
        return self::bindDevice($client, $deviceKey, $model, $appVersion, $ip);
    }

    /** Confere usuário/senha direto no servidor IPTV (Xtream: player_api.php). */
    public static function xtreamCheck(string $server, string $user, string $pass): array
    {
        $url = rtrim($server, '/') . '/player_api.php?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'DominiusPlay/1.0', CURLOPT_MAXFILESIZE => 2000000,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $code === 0 || $code >= 500) {
            return ['ok' => false, 'error' => 'server_down', 'message' => 'Servidor indisponível no momento. Tente de novo em instantes.'];
        }
        $j = json_decode((string) $raw, true);
        $info = is_array($j) ? ($j['user_info'] ?? null) : null;
        if (!is_array($info) || (int) ($info['auth'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'bad_login', 'message' => 'Usuário ou senha incorretos. Confira os dados ou fale com o suporte.'];
        }
        $exp = isset($info['exp_date']) && is_numeric($info['exp_date']) && (int) $info['exp_date'] > 0 ? (int) $info['exp_date'] : null;
        return ['ok' => true, 'exp' => $exp, 'trial' => (string) ($info['is_trial'] ?? '0') === '1'];
    }

    /** Atualização periódica do app: autenticada pelo token do aparelho. */
    public static function refresh(string $token, string $deviceKey, string $appVersion, string $ip): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return ['ok' => false, 'error' => 'invalid', 'message' => 'Sessão inválida.'];
        }
        $dev = Database::fetch('SELECT * FROM app_devices WHERE token_hash = :h AND device_key = :d', ['h' => hash('sha256', $token), 'd' => $deviceKey]);
        if (!$dev) {
            return ['ok' => false, 'error' => 'invalid', 'message' => 'Este aparelho precisa ser ativado de novo.'];
        }
        $client = Database::fetch('SELECT * FROM app_clients WHERE id = :id', ['id' => $dev['client_id']]);
        if (!$client || $client['status'] === 'blocked') {
            return ['ok' => false, 'error' => 'blocked', 'message' => 'Este acesso está bloqueado. Fale com o suporte.'];
        }
        Database::update('app_devices', ['last_seen_at' => Database::now(), 'app_version' => mb_substr($appVersion, 0, 20), 'ip' => mb_substr($ip, 0, 45)], 'id = :id', ['id' => $dev['id']]);
        Database::query('UPDATE app_clients SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $client['id']]);
        return ['ok' => true, 'payload' => self::payload($client)];
    }

    /** Dados públicos para a tela de ativação (marca, suporte, avisos de "login"). */
    public static function publicInfo(): array
    {
        return [
            'config' => self::publicConfig(),
            'notices' => self::notices('login', null),
            'update' => self::updateInfo(),
        ];
    }

    private static function publicConfig(): array
    {
        $f = self::cfgFields();
        return [
            'app_name' => self::cfg('app_name', $f['app_name'][1]),
            'support_whatsapp' => preg_replace('/\D+/', '', self::cfg('support_whatsapp', $f['support_whatsapp'][1])) ?: $f['support_whatsapp'][1],
            'expired_title' => self::cfg('expired_title', $f['expired_title'][1]),
            'expired_message' => self::cfg('expired_message', $f['expired_message'][1]),
        ];
    }

    public static function isExpired(array $client): bool
    {
        return $client['expires_at'] !== null && strtotime($client['expires_at'] . ' UTC') < time();
    }

    private static function payload(array $client): array
    {
        $expired = self::isExpired($client);
        $out = [
            'status' => $expired ? 'expired' : 'active',
            'client' => [
                'name' => $client['name'],
                'is_trial' => (bool) $client['is_trial'],
                'expires_at' => $client['expires_at'] ? gmdate('c', strtotime($client['expires_at'] . ' UTC')) : null,
                'seconds_left' => $client['expires_at'] ? max(0, strtotime($client['expires_at'] . ' UTC') - time()) : null,
            ],
            'config' => self::publicConfig(),
            'notices' => self::notices(null, $client),
            'update' => self::updateInfo(),
            'server_time' => gmdate('c'),
        ];
        if (!$expired) {
            $server = (string) ($client['server_url'] ?: self::cfg('default_server', ''));
            $pass = $client['password_enc'] ? (string) Crypto::decrypt($client['password_enc']) : '';
            $out['xtream'] = ['server' => $server, 'username' => (string) $client['username'], 'password' => $pass];
        }
        return $out;
    }

    // ------------------------------------------------------------------ avisos e publicidade

    /** Avisos válidos agora para o público do cliente (ou só os de "login" quando $client é null). */
    public static function notices(?string $placement, ?array $client): array
    {
        $now = Database::now();
        $rows = Database::fetchAll(
            'SELECT * FROM app_notices WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :n1) AND (ends_at IS NULL OR ends_at >= :n2) ORDER BY sort_order, id',
            ['n1' => $now, 'n2' => $now]
        );
        $out = [];
        foreach ($rows as $r) {
            if ($placement !== null && $r['placement'] !== $placement) {
                continue;
            }
            if ($placement === null && $r['placement'] === 'login') {
                continue;
            }
            if ($client !== null) {
                $trial = (bool) $client['is_trial'];
                if (($r['audience'] === 'trial' && !$trial) || ($r['audience'] === 'paid' && $trial)) {
                    continue;
                }
            } elseif ($r['audience'] !== 'all') {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => $r['kind'],
                'placement' => $r['placement'],
                'title' => $r['title'],
                'body' => (string) $r['body'],
                'image' => $r['image_path'] ? self::baseUrl() . '/uploads/app/' . rawurlencode(basename((string) $r['image_path'])) : null,
                'link_url' => $r['link_url'],
                'link_label' => $r['link_label'] ?: 'Saiba mais',
            ];
        }
        return $out;
    }

    public static function noticeEvent(int $id, string $event): void
    {
        $col = $event === 'click' ? 'clicks' : 'views';
        Database::query("UPDATE app_notices SET {$col} = {$col} + 1 WHERE id = :id", ['id' => $id]);
    }

    private static function baseUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', 'https://dominiusplay.top'), '/');
    }

    // ------------------------------------------------------------------ versões do APK

    public static function latestVersion(): ?array
    {
        try {
            return Database::fetch('SELECT * FROM app_versions ORDER BY version_code DESC LIMIT 1');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function updateInfo(): ?array
    {
        $v = self::latestVersion();
        if (!$v) {
            return null;
        }
        return [
            'version_code' => (int) $v['version_code'],
            'version_name' => $v['version_name'],
            'url' => self::baseUrl() . '/app',
            'sha256' => $v['sha256'],
            'mandatory' => (bool) $v['mandatory'],
            'notes' => (string) $v['notes'],
        ];
    }

    public static function apkDir(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/app';
    }

    // ------------------------------------------------------------------ uploads

    /**
     * Salva imagem (jpg/png/webp, até 2 MB) ou APK (até 150 MB) enviados pelo painel.
     * @return array{ok:bool,file?:string,size?:int,sha256?:string,error?:string}
     */
    public static function saveUpload(?array $file, string $kind): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Nenhum arquivo enviado.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'Arquivo grande demais.' : 'Falha no envio do arquivo.'];
        }
        $tmp = (string) $file['tmp_name'];
        $size = (int) $file['size'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Envio inválido.'];
        }
        $dir = self::apkDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Pasta de envio indisponível.'];
        }
        if ($kind === 'image') {
            if ($size > 2 * 1024 * 1024) {
                return ['ok' => false, 'error' => 'A imagem deve ter até 2 MB.'];
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if ($ext === null || @getimagesize($tmp) === false) {
                return ['ok' => false, 'error' => 'Use uma imagem JPG, PNG ou WEBP.'];
            }
            $name = 'img-' . bin2hex(random_bytes(8)) . '.' . $ext;
        } else {
            if ($size > 150 * 1024 * 1024 || $size < 100 * 1024) {
                return ['ok' => false, 'error' => 'O APK deve ter entre 100 KB e 150 MB.'];
            }
            $fh = @fopen($tmp, 'rb');
            $magic = $fh ? fread($fh, 4) : '';
            if ($fh) {
                fclose($fh);
            }
            if ($magic !== "PK\x03\x04" || !str_ends_with(strtolower((string) ($file['name'] ?? '')), '.apk')) {
                return ['ok' => false, 'error' => 'Envie um arquivo .apk válido.'];
            }
            $name = 'dominiusplay-' . bin2hex(random_bytes(6)) . '.apk';
        }
        $dest = $dir . '/' . $name;
        $moved = PHP_SAPI === 'cli' ? @copy($tmp, $dest) : @move_uploaded_file($tmp, $dest);
        if (!$moved) {
            return ['ok' => false, 'error' => 'Não foi possível salvar o arquivo.'];
        }
        @chmod($dest, 0644);
        return ['ok' => true, 'file' => $name, 'size' => $size, 'sha256' => hash_file('sha256', $dest) ?: ''];
    }
}
