<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class Heartbeat
{
    public static function beat(string $component, string $info = ''): void
    {
        Db::run('INSERT INTO heartbeats (component, host, pid, info, beat_at) VALUES (?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE host = VALUES(host), pid = VALUES(pid), info = VALUES(info), beat_at = NOW()',
            [$component, gethostname() ?: null, getmypid() ?: null, mb_substr($info, 0, 255)]);
    }

    /** Idade do último sinal em segundos (null = nunca). */
    public static function age(string $component): ?int
    {
        $v = Db::value('SELECT TIMESTAMPDIFF(SECOND, beat_at, NOW()) FROM heartbeats WHERE component = ?', [$component]);
        return $v === null ? null : (int) $v;
    }

    public static function get(string $component): ?array
    {
        return Db::one('SELECT *, TIMESTAMPDIFF(SECOND, beat_at, NOW()) AS age FROM heartbeats WHERE component = ?', [$component]);
    }
}
