<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Services\EvolutionClient;
use App\Services\NurtureService;

/**
 * Painel → WhatsApp: status da conexão, QR code para parear o chip, desconectar e enviar mensagem de teste.
 * Restrito a administradores (rota dentro do grupo AdminOnly).
 */
final class WhatsappController extends AdminController
{
    public function index(Request $request): void
    {
        $this->view('settings/whatsapp', [
            'state' => EvolutionClient::state(),
            'instance' => EvolutionClient::instance(),
            'sequenceOn' => NurtureService::enabled(),
        ], 'WhatsApp');
    }

    /** Imagem do QR code (mesma origem, só para admin logado). */
    public function qr(Request $request): void
    {
        $png = EvolutionClient::state() === 'open' ? null : EvolutionClient::qrPng();
        if ($png === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $png;
        exit;
    }

    public function disconnect(Request $request): void
    {
        $ok = EvolutionClient::logout();
        audit('whatsapp_disconnect');
        flash($ok ? 'success' : 'error', $ok ? 'WhatsApp desconectado. Gere um novo QR code para conectar outro chip.' : 'Não foi possível desconectar agora.');
        redirect('/admin/configuracoes/whatsapp');
    }

    public function test(Request $request): void
    {
        $phone = $request->str('phone', '', 30);
        if (EvolutionClient::state() !== 'open') {
            flash('error', 'O WhatsApp não está conectado. Escaneie o QR code primeiro.');
            redirect('/admin/configuracoes/whatsapp');
        }
        // O painel é usado no Brasil: sem DDI (até 11 dígitos), completa com 55.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits !== '' && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        $res = NurtureService::sendWhatsapp($digits, 'Teste do painel Dominius Play: a conexão do WhatsApp está funcionando. ✅');
        audit('whatsapp_test');
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Mensagem de teste enviada.' : 'Falha ao enviar: ' . (string) $res['error']);
        redirect('/admin/configuracoes/whatsapp');
    }
}
