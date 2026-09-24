<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Db;
use App\Core\Jwt;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\HealthService;
use App\Services\WebhookService;

final class ApiController
{
    public static function health(): never
    {
        $h = HealthService::basic();
        Response::json($h, $h['status'] === 'ok' ? 200 : 503);
    }

    public static function login(): never
    {
        $body = Request::json() ?? $_POST;
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $ip = Request::ip();
        if (RateLimiter::tooManyLoginAttempts($ip, $email)) {
            Response::json(['error' => 'too_many_attempts'], 429);
        }
        $user = Db::one('SELECT id, name, email, role, password_hash, active FROM users WHERE email = ?', [$email]);
        if (!$user || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            RateLimiter::hitLogin($ip, $email);
            Logger::warning('auth', 'Falha de login na API', ['email' => $email]);
            Response::json(['error' => 'invalid_credentials'], 401);
        }
        RateLimiter::clearLogin($email);
        Logger::info('auth', 'Login na API', ['email' => $email, '_user_id' => (int) $user['id']]);
        $ttl = 3600;
        Response::json(['access_token' => Jwt::encode(['sub' => (int) $user['id'], 'role' => $user['role']], $ttl), 'token_type' => 'Bearer', 'expires_in' => $ttl]);
    }

    private static function requireJwt(): array
    {
        $h = (string) Request::header('Authorization');
        $claims = str_starts_with($h, 'Bearer ') ? Jwt::decode(substr($h, 7)) : null;
        if (!$claims) {
            Response::json(['error' => 'unauthorized'], 401);
        }
        $user = Db::one('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1', [(int) $claims['sub']]);
        if (!$user) {
            Response::json(['error' => 'unauthorized'], 401);
        }
        return $user;
    }

    public static function me(): never
    {
        Response::json(['user' => self::requireJwt()]);
    }

    public static function stats(): never
    {
        self::requireJwt();
        $row = Db::one("SELECT
            (SELECT COUNT(*) FROM customers) AS customers,
            (SELECT COUNT(*) FROM invoices WHERE status = 'open') AS open_invoices,
            (SELECT COUNT(*) FROM messages WHERE status = 'pending') AS pending_messages,
            (SELECT COUNT(*) FROM messages WHERE sent_at >= CURDATE()) AS sent_today,
            (SELECT COUNT(*) FROM messages WHERE status = 'failed' AND updated_at >= CURDATE()) AS failed_today");
        Response::json(['stats' => array_map('intval', $row ?? [])]);
    }

    public static function messages(): never
    {
        self::requireJwt();
        $status = Request::str('status');
        $limit = max(1, min(200, Request::int('limit', 50)));
        $params = [];
        $where = '1=1';
        if ($status !== '') {
            $where = 'status = ?';
            $params[] = $status;
        }
        $rows = Db::all("SELECT id, event, status, destination, attempts, available_at, sent_at, last_error, created_at
            FROM messages WHERE $where ORDER BY id DESC LIMIT $limit", $params);
        foreach ($rows as &$r) {
            $r['destination'] = mask_phone($r['destination']);
        }
        Response::json(['data' => $rows]);
    }

    public static function webhookSgp(): never
    {
        [$status, $body] = WebhookService::handle('sgp');
        Response::json($body, $status);
    }

    public static function webhookWhatsapp(): never
    {
        [$status, $body] = WebhookService::handle('whatsapp');
        Response::json($body, $status);
    }

    /** Health completo: somente localhost (Nginx) + INTERNAL_API_SECRET. */
    public static function internalHealth(): never
    {
        $secret = (string) App::env('INTERNAL_API_SECRET', '');
        $sent = (string) Request::header('X-Internal-Secret');
        if ($secret === '' || !hash_equals($secret, $sent)) {
            Response::json(['error' => 'forbidden'], 403);
        }
        $checks = HealthService::full(false);
        $ok = true;
        foreach (['database', 'migrations', 'storage'] as $k) {
            if (($checks[$k]['status'] ?? 'fail') !== 'ok') {
                $ok = false;
            }
        }
        Response::json(['status' => $ok ? 'ok' : 'fail', 'version' => App::version(), 'release' => App::releaseName(), 'checks' => $checks], $ok ? 200 : 503);
    }
}
