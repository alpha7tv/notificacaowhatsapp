<?php
declare(strict_types=1);

namespace App\Core;

/** Configurações editáveis pelo painel (tabela settings). Segredos são criptografados com APP_KEY. */
final class Settings
{
    public const SECRET_KEYS = ['sgp_token', 'evolution_apikey'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT skey, svalue, encrypted FROM settings') as $r) {
                $v = $r['svalue'];
                if ((int) $r['encrypted'] === 1 && $v !== null && $v !== '') {
                    try {
                        $v = Crypto::decrypt($v);
                    } catch (\Throwable) {
                        $v = null;
                    }
                }
                self::$cache[$r['skey']] = $v;
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $encrypted = in_array($key, self::SECRET_KEYS, true);
        $stored = $value === null ? null : (string) $value;
        if ($encrypted && $stored !== null && $stored !== '') {
            $stored = Crypto::encrypt($stored);
        }
        Db::run(
            'INSERT INTO settings (skey, svalue, encrypted, updated_by, updated_at) VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), encrypted = VALUES(encrypted), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$key, $stored, $encrypted ? 1 : 0, $userId]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function isHomologation(): bool
    {
        return self::get('mode', 'homologation') !== 'production';
    }
}
