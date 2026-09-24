<?php
declare(strict_types=1);

/* API — https://api.minhafiberlink.com.br/api/v1/... */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Response;
use App\Core\Request;
use App\Core\Router;
use App\Http\ApiController;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (is_file(storage_path('maintenance.flag'))) {
    header('Retry-After: 60');
    Response::json(['status' => 'maintenance', 'version' => App::version()], 503);
}

$r = new Router();
$r->get('/api/v1/health', [ApiController::class, 'health']);
$r->post('/api/v1/auth/login', [ApiController::class, 'login']);
$r->get('/api/v1/me', [ApiController::class, 'me']);
$r->get('/api/v1/stats', [ApiController::class, 'stats']);
$r->get('/api/v1/messages', [ApiController::class, 'messages']);
$r->post('/api/v1/webhooks/sgp', [ApiController::class, 'webhookSgp']);
$r->post('/api/v1/webhooks/whatsapp', [ApiController::class, 'webhookWhatsapp']);
$r->get('/api/v1/internal/health', [ApiController::class, 'internalHealth']);

$r->dispatch(Request::method(), Request::path(), static function (bool $methodNotAllowed) {
    Response::json(['error' => $methodNotAllowed ? 'method_not_allowed' : 'not_found'], $methodNotAllowed ? 405 : 404);
});
