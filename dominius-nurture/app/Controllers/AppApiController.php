<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\AppService;

/**
 * API pública do aplicativo Dominius Play (JSON). Sem sessão e sem CSRF: o app autentica por código de
 * ativação (uma vez) e depois por token do aparelho. Todas as rotas têm limite de tentativas por IP.
 */
final class AppApiController
{
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        return is_array($json) ? $json : $_POST;
    }

    private function limited(Request $request, string $bucket, int $max, int $seconds): void
    {
        if (!RateLimiter::hit('app-' . $bucket . ':' . $request->ip(), $max, $seconds)) {
            header('Retry-After: ' . $seconds);
            json_response(['ok' => false, 'error' => 'rate_limited', 'message' => 'Muitas tentativas. Tente de novo em alguns minutos.'], 429);
        }
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store');
    }

    /** Marca, suporte, avisos da tela de ativação e versão mais recente. */
    public function info(Request $request): void
    {
        $this->limited($request, 'info', 120, 3600);
        $this->noStore();
        json_response(['ok' => true] + AppService::publicInfo());
    }

    public function activate(Request $request): void
    {
        $this->limited($request, 'act', 12, 3600);
        $this->noStore();
        $b = $this->body();
        $res = AppService::activate(
            (string) ($b['code'] ?? ''),
            (string) ($b['device_key'] ?? ''),
            (string) ($b['model'] ?? ''),
            (string) ($b['app_version'] ?? ''),
            $request->ip()
        );
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'], 'message' => $res['message']], $res['error'] === 'device_limit' ? 409 : 422);
        }
        json_response(['ok' => true] + $res['payload']);
    }

    public function refresh(Request $request): void
    {
        $this->limited($request, 'ref', 240, 3600);
        $this->noStore();
        $b = $this->body();
        $res = AppService::refresh((string) ($b['token'] ?? ''), (string) ($b['device_key'] ?? ''), (string) ($b['app_version'] ?? ''), $request->ip());
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'], 'message' => $res['message']], 401);
        }
        json_response(['ok' => true] + $res['payload']);
    }

    /** Contagem de exibições e cliques dos avisos. */
    public function notice(Request $request): void
    {
        $this->limited($request, 'ntc', 600, 3600);
        $b = $this->body();
        $id = (int) ($b['id'] ?? 0);
        $event = ($b['event'] ?? '') === 'click' ? 'click' : 'view';
        if ($id > 0) {
            AppService::noticeEvent($id, $event);
        }
        json_response(['ok' => true]);
    }

    /** /app → baixa o APK mais recente (também funciona digitado no Downloader da TV). */
    public function download(Request $request): void
    {
        $v = AppService::latestVersion();
        $file = $v ? AppService::apkDir() . '/' . basename((string) $v['file_name']) : '';
        if (!$v || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('O aplicativo ainda não está disponível para download.');
        }
        Database::query('UPDATE app_versions SET downloads = downloads + 1 WHERE id = :id', ['id' => $v['id']]);
        redirect('/uploads/app/' . rawurlencode(basename((string) $v['file_name'])), 302);
    }
}
