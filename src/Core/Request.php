<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?string $rawBody = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $p = '/' . trim(rawurldecode($p), '/');
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    /** IP do cliente. O Nginx conecta direto ao PHP-FPM, então REMOTE_ADDR é confiável. */
    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) {
            return (string) $_SERVER['CONTENT_TYPE'];
        }
        return null;
    }

    public static function rawBody(): string
    {
        if (self::$rawBody === null) {
            self::$rawBody = (string) file_get_contents('php://input');
        }
        return self::$rawBody;
    }

    public static function json(): ?array
    {
        $d = json_decode(self::rawBody(), true);
        return is_array($d) ? $d : null;
    }
}
