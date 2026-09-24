<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Log centralizado: grava no banco (tabela app_logs, exibida em SISTEMA > LOGS)
 * e em arquivo (storage/logs/<categoria>.log). Tudo passa pelo Masker.
 */
final class Logger
{
    public const CATEGORIES = [
        'app' => 'Aplicação', 'sgp' => 'SGP', 'whatsapp' => 'WhatsApp', 'worker' => 'Worker',
        'scheduler' => 'Scheduler', 'webhooks' => 'Webhooks', 'auth' => 'Autenticação', 'deploy' => 'Deploy',
    ];
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private static bool $echo = false;

    /** Processos CLI (worker/scheduler) também escrevem na saída (capturada pelo journald). */
    public static function echoToStdout(bool $on = true): void
    {
        self::$echo = $on;
    }

    public static function info(string $cat, string $msg, array $ctx = []): void
    {
        self::log($cat, 'info', $msg, $ctx);
    }

    public static function warning(string $cat, string $msg, array $ctx = []): void
    {
        self::log($cat, 'warning', $msg, $ctx);
    }

    public static function error(string $cat, string $msg, array $ctx = []): void
    {
        self::log($cat, 'error', $msg, $ctx);
    }

    public static function log(string $category, string $level, string $message, array $context = []): void
    {
        $category = isset(self::CATEGORIES[$category]) ? $category : 'app';
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        $message = mb_substr(Masker::string($message), 0, 1000);
        $ctxJson = $context ? Masker::json($context) : null;
        $userId = $context['_user_id'] ?? (Auth::id() ?? null);
        $ip = App::isCli() ? null : Request::ip();

        $line = sprintf("[%s] %s.%s: %s%s\n", date('Y-m-d H:i:s'), $category, strtoupper($level), $message, $ctxJson ? ' ' . $ctxJson : '');
        if (self::$echo) {
            fwrite(STDOUT, $line);
        }
        self::toFile($category, $line);

        try {
            Db::run(
                'INSERT INTO app_logs (category, level, message, context, user_id, ip, created_at) VALUES (?,?,?,?,?,?,NOW(3))',
                [$category, $level, $message, $ctxJson, $userId, $ip]
            );
        } catch (\Throwable) {
            // Banco indisponível: o arquivo de log já foi gravado.
        }
    }

    private static function toFile(string $category, string $line): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/' . $category . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
