<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Lock;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Executado a cada minuto pelo timer systemd "fiberlink-scheduler.timer".
 * Cada rotina roda com lock distribuído: nunca há duas execuções simultâneas da mesma rotina.
 */
final class Scheduler
{
    /** @return array<string, array{ttl:int, fn:callable():string}> */
    private static function tasks(): array
    {
        return [
            'heartbeat' => ['ttl' => 120, 'fn' => static fn () => 'ok'],
            'sgp_sync' => ['ttl' => 3600, 'fn' => static function () {
                $r = (new SyncService())->run();
                if (($r['status'] ?? '') === 'error') {
                    throw new \RuntimeException('Sincronização falhou: ' . ($r['message'] ?? ''));
                }
                return (string) ($r['message'] ?? $r['status']);
            }],
            'plan_due' => ['ttl' => 600, 'fn' => static fn () => Planner::planDue() . ' mensagem(ns) planejada(s)'],
            'reconcile_payments' => ['ttl' => 1800, 'fn' => [self::class, 'reconcilePayments']],
            'reconcile_status' => ['ttl' => 900, 'fn' => [self::class, 'reconcileStatus']],
            'requeue_stale' => ['ttl' => 300, 'fn' => [self::class, 'requeueStale']],
            'cleanup' => ['ttl' => 1800, 'fn' => [self::class, 'cleanup']],
        ];
    }

    public static function runDue(): array
    {
        Heartbeat::beat('scheduler', 'executando');
        $ran = [];
        // Intervalo da sincronização SGP é configurável pelo painel.
        $syncMin = max(5, Settings::int('sgp_sync_interval_minutes', 30));
        Db::run('UPDATE scheduled_tasks SET interval_seconds = ? WHERE name = ?', [$syncMin * 60, 'sgp_sync']);

        $due = Db::all('SELECT name FROM scheduled_tasks WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY name');
        foreach ($due as $row) {
            $ran[$row['name']] = self::runTask($row['name']);
        }
        Heartbeat::beat('scheduler', 'ocioso');
        return $ran;
    }

    public static function runTask(string $name): string
    {
        $tasks = self::tasks();
        if (!isset($tasks[$name])) {
            return 'desconhecida';
        }
        $task = $tasks[$name];
        $interval = (int) (Db::value('SELECT interval_seconds FROM scheduled_tasks WHERE name = ?', [$name]) ?? 600);

        $result = Lock::run('task:' . $name, $task['ttl'], static function () use ($name, $task, $interval) {
            $t = microtime(true);
            Db::run("UPDATE scheduled_tasks SET last_started_at = NOW(), last_status = 'running' WHERE name = ?", [$name]);
            try {
                $msg = ($task['fn'])();
                $status = 'ok';
            } catch (\Throwable $e) {
                Db::reset();
                $msg = $e->getMessage();
                $status = 'error';
                Logger::error('scheduler', "Rotina $name falhou: $msg");
            }
            $ms = (int) round((microtime(true) - $t) * 1000);
            Db::run('UPDATE scheduled_tasks SET last_finished_at = NOW(), last_status = ?, last_duration_ms = ?, last_message = ?,
                next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE name = ?',
                [$status, $ms, mb_substr((string) $msg, 0, 500), $status === 'ok' ? $interval : min($interval, 300), $name]);
            return $status;
        });

        if ($result === null) {
            Db::run("UPDATE scheduled_tasks SET last_message = 'Pulada: já em execução em outro processo' WHERE name = ?", [$name]);
            return 'skipped';
        }
        return $result;
    }

    /** Marca uma rotina para rodar no próximo minuto (botão "Executar agora"). */
    public static function trigger(string $name): void
    {
        Db::run('UPDATE scheduled_tasks SET next_run_at = NOW() WHERE name = ?', [$name]);
    }

    public static function reconcilePayments(): string
    {
        // 1) Cancela lembretes de faturas que já não estão em aberto
        $cancelled = Db::run("UPDATE messages m JOIN invoices i ON i.id = m.invoice_id
            SET m.status = 'cancelled', m.status_reason = IF(i.status = 'paid', 'Fatura paga', 'Fatura cancelada')
            WHERE m.status = 'pending' AND m.event IN ('before_due','due_today','after_due') AND i.status <> 'open'")->rowCount();

        // 2) Antes de cobrar atraso, confirma no SGP que a fatura continua em aberto
        $rechecked = 0;
        $sync = new SyncService();
        $customers = Db::all("SELECT DISTINCT c.* FROM messages m JOIN customers c ON c.id = m.customer_id
            WHERE m.status = 'pending' AND m.event = 'after_due' AND m.available_at <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
              AND (c.last_synced_at IS NULL OR c.last_synced_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)) LIMIT 100");
        foreach ($customers as $c) {
            try {
                $sync->syncCustomer($c);
                $rechecked++;
            } catch (\Throwable $e) {
                Logger::warning('sgp', 'Reconciliação: falha ao reconsultar cliente #' . $c['id'] . ': ' . $e->getMessage());
            }
        }
        if ($rechecked) {
            $cancelled += Db::run("UPDATE messages m JOIN invoices i ON i.id = m.invoice_id
                SET m.status = 'cancelled', m.status_reason = 'Fatura paga (reconciliação)'
                WHERE m.status = 'pending' AND m.event IN ('before_due','due_today','after_due') AND i.status <> 'open'")->rowCount();
        }
        return "$cancelled lembrete(s) cancelado(s), $rechecked cliente(s) reconsultado(s)";
    }

    public static function reconcileStatus(): string
    {
        $n = Db::run("UPDATE messages m JOIN contracts k ON k.id = m.contract_id
            SET m.status = 'cancelled', m.status_reason = 'Contrato cancelado'
            WHERE m.status = 'pending' AND m.event IN ('before_due','due_today','after_due','suspended') AND k.status = 'cancelled'")->rowCount();
        $n += Db::run("UPDATE messages m JOIN contracts k ON k.id = m.contract_id
            SET m.status = 'cancelled', m.status_reason = 'Contrato reativado antes do envio'
            WHERE m.status = 'pending' AND m.event = 'suspended' AND k.status = 'active'")->rowCount();
        return "$n mensagem(ns) ajustada(s)";
    }

    public static function requeueStale(): string
    {
        $failed = Db::run("UPDATE messages SET status = 'failed', locked_by = NULL, last_error = 'Travada em processamento e sem tentativas restantes'
            WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND attempts >= max_attempts")->rowCount();
        $n = Db::run("UPDATE messages SET status = 'pending', locked_by = NULL, locked_at = NULL,
            available_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE), last_error = 'Retomada após interrupção do worker'
            WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->rowCount();
        if ($n || $failed) {
            Logger::warning('scheduler', "$n mensagem(ns) devolvida(s) à fila, $failed marcada(s) como falha");
        }
        return "$n devolvida(s), $failed falha(s)";
    }

    public static function cleanup(): string
    {
        $days = max(7, Settings::int('log_retention_days', 90));
        $logs = Db::run('DELETE FROM app_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 50000', [$days])->rowCount();
        $wh = Db::run('DELETE FROM webhook_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 50000', [$days])->rowCount();
        Db::run('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        Db::run('DELETE FROM locks WHERE expires_at < NOW()');
        Db::run('DELETE FROM sync_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 60 DAY)');
        $tmp = 0;
        foreach (glob(storage_path('tmp') . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 2 * 86400 && @unlink($f)) {
                $tmp++;
            }
        }
        return "$logs log(s), $wh webhook(s), $tmp temporário(s) removido(s)";
    }
}
