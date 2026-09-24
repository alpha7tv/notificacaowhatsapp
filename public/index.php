<?php
declare(strict_types=1);

/* Painel administrativo — https://painel.minhafiberlink.com.br */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Http\AuthController;
use App\Http\ConfigController;
use App\Http\PanelController;
use App\Http\SystemController;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");

if (is_file(storage_path('maintenance.flag'))) {
    http_response_code(503);
    header('Retry-After: 60');
    echo View::render('errors/maintenance', [], null);
    exit;
}

Auth::startSession();
if (Request::method() === 'POST') {
    Csrf::verify();
}

$r = new Router();
$r->get('/login', [AuthController::class, 'form']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout', [AuthController::class, 'logout']);

$r->get('/', [PanelController::class, 'dashboard']);
$r->get('/clientes', [PanelController::class, 'customers']);
$r->post('/clientes/adicionar', [PanelController::class, 'addCustomer']);
$r->post('/clientes/importar', [PanelController::class, 'importCustomers']);
$r->get('/clientes/{id}', [PanelController::class, 'customer']);
$r->post('/clientes/{id}/sincronizar', [PanelController::class, 'syncCustomer']);
$r->post('/clientes/{id}/optout', [PanelController::class, 'toggleOptOut']);
$r->get('/faturas', [PanelController::class, 'invoices']);
$r->get('/mensagens', [PanelController::class, 'messages']);
$r->get('/mensagens/{id}', [PanelController::class, 'message']);
$r->post('/mensagens/{id}/reenviar', [PanelController::class, 'retryMessage']);
$r->post('/mensagens/{id}/cancelar', [PanelController::class, 'cancelMessage']);

$r->get('/regras', [ConfigController::class, 'rules']);
$r->post('/regras/salvar', [ConfigController::class, 'saveRule']);
$r->post('/regras/{id}/excluir', [ConfigController::class, 'deleteRule']);
$r->get('/templates', [ConfigController::class, 'templates']);
$r->get('/templates/{id}', [ConfigController::class, 'editTemplate']);
$r->post('/templates/salvar', [ConfigController::class, 'saveTemplate']);
$r->get('/integracoes', [ConfigController::class, 'integrations']);
$r->post('/integracoes/sgp', [ConfigController::class, 'saveSgp']);
$r->post('/integracoes/sgp/testar', [ConfigController::class, 'testSgp']);
$r->post('/integracoes/sgp/sincronizar', [ConfigController::class, 'syncNow']);
$r->post('/integracoes/whatsapp', [ConfigController::class, 'saveWhatsapp']);
$r->post('/integracoes/whatsapp/testar', [ConfigController::class, 'testWhatsapp']);
$r->post('/integracoes/whatsapp/webhook', [ConfigController::class, 'setWebhook']);
$r->get('/integracoes/whatsapp/conectar', [ConfigController::class, 'connectWhatsapp']);
$r->post('/integracoes/whatsapp/desconectar', [ConfigController::class, 'logoutWhatsapp']);
$r->post('/integracoes/webhooks/revelar', [ConfigController::class, 'revealWebhooks']);
$r->get('/homologacao', [ConfigController::class, 'homologation']);
$r->post('/homologacao/salvar', [ConfigController::class, 'saveHomologation']);
$r->post('/homologacao/teste', [ConfigController::class, 'sendTest']);
$r->post('/homologacao/validar-numero', [ConfigController::class, 'validateNumber']);
$r->post('/homologacao/liberar', [ConfigController::class, 'release']);
$r->post('/homologacao/voltar', [ConfigController::class, 'backToHomologation']);

$r->get('/sistema/servidor', [SystemController::class, 'server']);
$r->post('/sistema/rotinas/executar', [SystemController::class, 'runTask']);
$r->get('/sistema/atualizacoes', [SystemController::class, 'updates']);
$r->post('/sistema/tarefas', [SystemController::class, 'requestJob']);
$r->get('/sistema/tarefas/{id}', [SystemController::class, 'job']);
$r->get('/sistema/logs', [SystemController::class, 'logs']);
$r->get('/sistema/webhooks', [SystemController::class, 'webhooks']);
$r->get('/sistema/configuracoes', [SystemController::class, 'settings']);
$r->post('/sistema/configuracoes', [SystemController::class, 'saveSettings']);
$r->get('/sistema/usuarios', [SystemController::class, 'users']);
$r->post('/sistema/usuarios/salvar', [SystemController::class, 'saveUser']);
$r->get('/sistema/auditoria', [SystemController::class, 'audit']);
$r->get('/conta/senha', [AuthController::class, 'passwordForm']);
$r->post('/conta/senha', [AuthController::class, 'changePassword']);

$r->dispatch(Request::method(), Request::path(), static function (bool $methodNotAllowed) {
    Auth::require();
    View::page('errors/404', ['title' => 'Página não encontrada'], $methodNotAllowed ? 405 : 404);
});
