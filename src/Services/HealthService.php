<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\RedisConn;
use App\Core\Settings;
use App\Integrations\EvolutionClient;
use App\Integrations\SgpClient;

final class HealthService
{
    /** Verificação leve e pública (GET /api/v1/health). */
    public static function basic(): array
    {
        $db = Db::ping();
        $redis = RedisConn::status();
        $ok = $db && $redis !== 'fail';
        return [
            'status' => $ok ? 'ok' : 'degraded',
            'version' => App::version(),
            'time' => date('c'),
            'checks' => ['database' => $db ? 'ok' : 'fail', 'redis' => $redis],
        ];
    }

    /** @return array{sgp:string, whatsapp:string, sgp_detail:string, whatsapp_detail:string} ok|not_configured|fail */
    public static function integrations(bool $live = true): array
    {
        $out = ['sgp' => 'not_configured', 'sgp_detail' => '', 'whatsapp' => 'not_configured', 'whatsapp_detail' => ''];
        $sgp = SgpClient::fromSettings();
        if ($sgp->configured()) {
            $lastOk = (string) Settings::get('sgp_last_ok_at', '');
            $lastRun = Db::one('SELECT status, message, started_at FROM sync_runs ORDER BY id DESC LIMIT 1');
            if ($lastOk !== '' && strtotime($lastOk) > strtotime('-1 day') && (!$lastRun || $lastRun['status'] !== 'error')) {
                $out['sgp'] = 'ok';
                $out['sgp_detail'] = 'Última comunicação OK ' . ago($lastOk);
            } elseif ($live) {
                $r = $sgp->test();
                $out['sgp'] = $r['ok'] ? 'ok' : 'fail';
                $out['sgp_detail'] = $r['ok'] ? 'Teste de conexão OK (' . $r['ms'] . ' ms)' : (string) $r['error'];
                if ($r['ok']) {
                    Settings::set('sgp_last_ok_at', now_str());
                }
            } else {
                $out['sgp'] = 'fail';
                $out['sgp_detail'] = 'Sem comunicação recente';
            }
        }
        $wa = EvolutionClient::fromSettings();
        if ($wa->configured()) {
            $state = $live ? $wa->connectionState() : Settings::get('whatsapp_state');
            if ($live && $state !== null) {
                Settings::set('whatsapp_state', $state);
                Settings::set('whatsapp_state_at', now_str());
            }
            $out['whatsapp'] = $state === 'open' ? 'ok' : 'fail';
            $out['whatsapp_detail'] = $state === null ? 'Evolution API não respondeu' : "Estado da conexão: $state";
        }
        return $out;
    }

    /** Verificação completa usada pelo health-check.sh e pelo painel. */
    public static function full(bool $liveIntegrations = true): array
    {
        $checks = [];
        $db = Db::ping();
        $checks['database'] = ['status' => $db ? 'ok' : 'fail'];
        $checks['redis'] = ['status' => RedisConn::status()];
        if ($db) {
            $pending = (new Migrator())->pending();
            $checks['migrations'] = ['status' => $pending ? 'fail' : 'ok', 'pending' => $pending];
            $w = Heartbeat::age('worker');
            $s = Heartbeat::age('scheduler');
            $checks['worker'] = ['status' => $w !== null && $w < 120 ? 'ok' : 'fail', 'age' => $w];
            $checks['scheduler'] = ['status' => $s !== null && $s < 180 ? 'ok' : 'fail', 'age' => $s];
            $q = Db::one("SELECT SUM(status = 'pending') AS pending, SUM(status = 'processing') AS processing,
                SUM(status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS failed_24h,
                SUM(status = 'pending' AND available_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS overdue
                FROM messages");
            $checks['queue'] = ['status' => 'ok'] + array_map('intval', $q ?? []);
            $integ = self::integrations($liveIntegrations);
            $checks['sgp'] = ['status' => $integ['sgp'], 'detail' => $integ['sgp_detail']];
            $checks['whatsapp'] = ['status' => $integ['whatsapp'], 'detail' => $integ['whatsapp_detail']];
            $checks['mode'] = ['status' => 'ok', 'value' => Settings::isHomologation() ? 'homologation' : 'production'];
        }
        $writable = true;
        foreach (['logs', 'sessions', 'tmp'] as $d) {
            $p = storage_path($d);
            if (!is_dir($p)) {
                @mkdir($p, 0775, true);
            }
            if (!is_writable($p)) {
                $writable = false;
            }
        }
        $checks['storage'] = ['status' => $writable ? 'ok' : 'fail'];
        $checks['timezone'] = ['status' => date_default_timezone_get() === 'America/Sao_Paulo' ? 'ok' : 'fail', 'value' => date_default_timezone_get()];
        return $checks;
    }
}
