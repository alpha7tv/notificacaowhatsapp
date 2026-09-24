<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    public static function form(): never
    {
        if (Auth::user()) {
            Response::redirect('/');
        }
        Response::html(View::render('auth/login', ['title' => 'Entrar', 'error' => null, 'email' => ''], null));
    }

    public static function login(): never
    {
        $email = Request::str('email');
        [$ok, $error] = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
        if (!$ok) {
            Response::html(View::render('auth/login', ['title' => 'Entrar', 'error' => $error, 'email' => $email], null), 422);
        }
        Response::redirect('/');
    }

    public static function logout(): never
    {
        Audit::log('logout', 'user', (string) Auth::id());
        Auth::logout();
        Response::redirect('/login');
    }

    public static function passwordForm(): never
    {
        Auth::require();
        View::page('auth/password', ['title' => 'Alterar senha']);
    }

    public static function changePassword(): never
    {
        $u = Auth::require();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!Auth::confirmPassword((string) ($_POST['current_password'] ?? ''))) {
            Response::flash('error', 'Senha atual incorreta.');
            Response::redirect('/conta/senha');
        }
        if (mb_strlen($new) < 10 || $new !== (string) ($_POST['confirm_password'] ?? '')) {
            Response::flash('error', 'A nova senha precisa ter 10+ caracteres e a confirmação deve ser igual.');
            Response::redirect('/conta/senha');
        }
        Db::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        Audit::log('user.password_change', 'user', (string) $u['id']);
        session_regenerate_id(true);
        Response::flash('success', 'Senha alterada.');
        Response::redirect('/');
    }
}
