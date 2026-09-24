<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(): void
    {
        $sent = (string) ($_POST['_csrf'] ?? Request::header('X-CSRF-Token') ?? '');
        if ($sent === '' || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            Response::html('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px">'
                . '<h1>Sessão expirada</h1><p>O formulário expirou. <a href="javascript:history.back()">Volte</a> e tente novamente.</p>', 419);
        }
    }
}
