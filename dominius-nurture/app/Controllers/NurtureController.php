<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\NurtureService;

/**
 * Descadastro da sequência de e-mails. O GET só mostra a confirmação (evita descadastro por scanners
 * de link); quem descadastra de fato é o POST.
 */
final class NurtureController extends Controller
{
    public function show(Request $request, string $token): void
    {
        $this->render($token, NurtureService::findByToken($token), false);
    }

    public function confirm(Request $request, string $token): void
    {
        $sub = NurtureService::unsubscribe($token);
        $this->render($token, $sub, $sub !== null);
    }

    private function render(string $token, ?array $sub, bool $done): void
    {
        $this->view('pages.unsubscribe', [
            'sub' => $sub,
            'token' => $token,
            'done' => $done,
            'seo' => $this->seo('Cancelar mensagens', 'Cancelar o recebimento das mensagens.', ['robots' => 'noindex,nofollow']),
        ]);
    }
}
