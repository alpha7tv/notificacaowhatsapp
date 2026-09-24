<?php
declare(strict_types=1);

namespace App\Core;

final class RedisConn
{
    private static mixed $conn = null;
    private static bool $tried = false;

    public static function configured(): bool
    {
        return (string) App::env('REDIS_HOST', '') !== '';
    }

    /** @return \Redis|null */
    public static function get(): mixed
    {
        if (!self::configured() || !class_exists(\Redis::class)) {
            return null;
        }
        if (self::$conn !== null) {
            return self::$conn;
        }
        if (self::$tried && !App::isCli()) {
            return null;
        }
        self::$tried = true;
        try {
            $r = new \Redis();
            $r->connect((string) App::env('REDIS_HOST'), (int) App::env('REDIS_PORT', 6379), 1.5);
            $pass = (string) App::env('REDIS_PASSWORD', '');
            if ($pass !== '') {
                $r->auth($pass);
            }
            $r->setOption(\Redis::OPT_PREFIX, (string) App::env('REDIS_PREFIX', 'fiberlink:'));
            self::$conn = $r;
        } catch (\Throwable) {
            self::$conn = null;
        }
        return self::$conn;
    }

    public static function reset(): void
    {
        self::$conn = null;
        self::$tried = false;
    }

    /** @return string ok | not_configured | fail */
    public static function status(): string
    {
        if (!self::configured()) {
            return 'not_configured';
        }
        if (!class_exists(\Redis::class)) {
            return 'fail';
        }
        try {
            $r = self::get();
            return $r && $r->ping() ? 'ok' : 'fail';
        } catch (\Throwable) {
            self::reset();
            return 'fail';
        }
    }
}
