<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $sessionStarted = false;

    public static function startSession(): void
    {
        if (self::$sessionStarted || App::isCli()) {
            return;
        }
        $dir = storage_path('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $lifetime = max(10, (int) App::env('SESSION_LIFETIME_MINUTES', 120)) * 60;
        ini_set('session.save_path', $dir);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('fl_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$sessionStarted = true;

        $last = (int) ($_SESSION['_last'] ?? 0);
        if ($last && time() - $last > $lifetime) {
            self::logout();
            session_start();
        }
        $_SESSION['_last'] = time();
    }

    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = Request::ip();
        if (RateLimiter::tooManyLoginAttempts($ip, $email)) {
            Logger::warning('auth', 'Login bloqueado por excesso de tentativas', ['email' => $email]);
            self::authFailLine($ip, $email, 'rate_limited');
            return [false, 'Muitas tentativas. Aguarde 15 minutos e tente novamente.'];
        }
        $user = Db::one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        $ok = $user && (int) $user['active'] === 1 && password_verify($password, $user['password_hash']);
        if (!$ok) {
            RateLimiter::hitLogin($ip, $email);
            Logger::warning('auth', 'Falha de login', ['email' => $email]);
            self::authFailLine($ip, $email, 'invalid');
            return [false, 'E-mail ou senha inválidos.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        RateLimiter::clearLogin($email);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        Db::update('users', ['last_login_at' => now_str()], 'id = ?', [$user['id']]);
        self::$user = null;
        Logger::info('auth', 'Login realizado', ['email' => $email, '_user_id' => (int) $user['id']]);
        Audit::log('login', 'user', (string) $user['id']);
        return [true, null];
    }

    /** Linha em formato simples para o Fail2Ban (storage/logs/auth-fail.log). */
    private static function authFailLine(string $ip, string $email, string $reason): void
    {
        @file_put_contents(
            storage_path('logs/auth-fail.log'),
            sprintf("%s FIBERLINK_AUTH_FAIL ip=%s reason=%s user=%s\n", date('Y-m-d H:i:s'), $ip, $reason, substr(hash('sha256', $email), 0, 12)),
            FILE_APPEND | LOCK_EX
        );
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            try {
                $u = Db::one('SELECT id, name, email, role, active FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
            } catch (\Throwable) {
                $u = null;
            }
            self::$user = ($u && (int) $u['active'] === 1) ? $u : null;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        if (App::isCli()) {
            return null;
        }
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function require(bool $admin = false): array
    {
        $u = self::user();
        if (!$u) {
            Response::redirect('/login');
        }
        if ($admin && $u['role'] !== 'admin') {
            http_response_code(403);
            View::page('errors/403', ['title' => 'Acesso negado'], 403);
        }
        return $u;
    }

    /** Confirma a senha do usuário logado (ações críticas: liberar produção, atualizar, revelar URLs). */
    public static function confirmPassword(string $password): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        return $hash !== '' && password_verify($password, $hash);
    }
}
