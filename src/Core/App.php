<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static array $env = [];
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $envFile = getenv('FIBERLINK_ENV_FILE') ?: BASE_PATH . '/.env';
        self::$env = is_file($envFile) ? Env::parseFile($envFile) : [];

        date_default_timezone_set((string) self::env('APP_TIMEZONE', 'America/Sao_Paulo'));
        mb_internal_encoding('UTF-8');

        $debug = self::debug();
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_exception_handler([self::class, 'handleException']);
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$env)) {
            return self::$env[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    public static function debug(): bool
    {
        return filter_var(self::env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
    }

    public static function isProduction(): bool
    {
        return self::env('APP_ENV', 'production') === 'production';
    }

    public static function version(): string
    {
        $f = BASE_PATH . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : '0.0.0';
    }

    /** Nome da release atual (diretório dentro de releases/), quando instalado pelo deploy. */
    public static function releaseName(): string
    {
        $real = realpath(BASE_PATH) ?: BASE_PATH;
        return basename($real);
    }

    public static function storagePath(): string
    {
        $p = (string) self::env('STORAGE_PATH', '');
        return $p !== '' ? $p : BASE_PATH . '/storage';
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    public static function handleException(\Throwable $e): void
    {
        try {
            Logger::error('app', 'Exceção não tratada: ' . $e->getMessage(), [
                'type' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (\Throwable) {
            error_log('[fiberlink] ' . $e->getMessage());
        }

        if (self::isCli()) {
            fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
            if (self::debug()) {
                fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
            }
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
        }
        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'internal_error', 'message' => self::debug() ? $e->getMessage() : 'Erro interno.']);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Erro</title><body style="font-family:sans-serif;padding:40px">'
                . '<h1>Erro interno</h1><p>Ocorreu um erro inesperado. O problema foi registrado em SISTEMA &gt; LOGS.</p>'
                . (self::debug() ? '<pre>' . htmlspecialchars((string) $e) . '</pre>' : '') . '</body>';
        }
        exit(1);
    }
}
