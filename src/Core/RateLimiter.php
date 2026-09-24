<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    private const WINDOW_MIN = 15;
    private const MAX_PER_EMAIL = 5;
    private const MAX_PER_IP = 20;

    public static function tooManyLoginAttempts(string $ip, string $email): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        $byEmail = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND created_at >= ?', [hash('sha256', $email), $since]);
        $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', [$ip, $since]);
        return $byEmail >= self::MAX_PER_EMAIL || $byIp >= self::MAX_PER_IP;
    }

    public static function hitLogin(string $ip, string $email): void
    {
        Db::run('INSERT INTO login_attempts (ip, email_hash, created_at) VALUES (?,?,NOW())', [$ip, hash('sha256', $email)]);
    }

    public static function clearLogin(string $email): void
    {
        Db::run('DELETE FROM login_attempts WHERE email_hash = ?', [hash('sha256', $email)]);
    }
}
