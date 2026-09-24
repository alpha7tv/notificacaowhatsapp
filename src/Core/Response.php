<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $path = parse_url($ref, PHP_URL_PATH);
        $query = parse_url($ref, PHP_URL_QUERY);
        // Só redireciona para caminhos locais (evita open redirect).
        $to = is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')
            ? $path . ($query ? '?' . $query : '') : $fallback;
        self::redirect($to);
    }
}
