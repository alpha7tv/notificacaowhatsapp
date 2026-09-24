<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Request;

/**
 * Fila de tarefas administrativas. O painel só REGISTRA o pedido; quem executa é o
 * agente root (deploy/agent.sh, timer systemd), que aceita apenas os tipos abaixo e
 * roda somente os scripts fixos do /deploy. Nenhum parâmetro vindo da web chega ao shell.
 */
final class SystemJobs
{
    public const TYPES = [
        'check_update' => 'Verificar atualização',
        'update' => 'Atualizar sistema',
        'backup' => 'Backup manual',
        'health' => 'Health check completo',
    ];

    public static function request(string $type, int $userId): array
    {
        if (!isset(self::TYPES[$type])) {
            return [false, 'Tipo de tarefa inválido.'];
        }
        $running = Db::value("SELECT id FROM system_jobs WHERE status IN ('queued','running') AND type IN ('update','backup') LIMIT 1");
        if ($running && in_array($type, ['update', 'backup'], true)) {
            return [false, 'Já existe uma atualização/backup em andamento (tarefa #' . $running . ').'];
        }
        $dup = Db::value("SELECT id FROM system_jobs WHERE status = 'queued' AND type = ? LIMIT 1", [$type]);
        if ($dup) {
            return [true, 'Tarefa já está na fila (#' . $dup . ').'];
        }
        $id = Db::insert('system_jobs', ['type' => $type, 'requested_by' => $userId, 'requested_ip' => Request::ip(), 'created_at' => now_str()]);
        Audit::log('system_job.request', 'system_job', (string) $id, ['type' => $type]);
        Logger::info('deploy', 'Tarefa solicitada pelo painel: ' . self::TYPES[$type] . " (#$id)");
        return [true, self::TYPES[$type] . " solicitada (tarefa #$id). O agente do servidor executa em até 1 minuto."];
    }

    /** Usado pelo agente root: reserva a próxima tarefa. Imprime "ID TIPO". */
    public static function claim(): ?array
    {
        // Tarefas "running" há mais de 2h são consideradas abandonadas.
        Db::run("UPDATE system_jobs SET status = 'failed', finished_at = NOW(), result = 'Tempo esgotado' WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        return Db::tx(static function () {
            $job = Db::one("SELECT id, type FROM system_jobs WHERE status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE");
            if (!$job) {
                return null;
            }
            Db::run("UPDATE system_jobs SET status = 'running', started_at = NOW(), log_file = ? WHERE id = ?", ['logs/deploy/job-' . $job['id'] . '.log', $job['id']]);
            return $job;
        });
    }

    public static function finish(int $id, string $status, string $result): void
    {
        $status = in_array($status, ['success', 'failed'], true) ? $status : 'failed';
        Db::run('UPDATE system_jobs SET status = ?, result = ?, finished_at = NOW() WHERE id = ?', [$status, mb_substr($result, 0, 1000), $id]);
        Logger::log('deploy', $status === 'success' ? 'info' : 'error', "Tarefa #$id finalizada: $status — $result");
    }

    public static function recent(int $limit = 20): array
    {
        return Db::all('SELECT j.*, u.name AS user_name FROM system_jobs j LEFT JOIN users u ON u.id = j.requested_by ORDER BY j.id DESC LIMIT ' . (int) $limit);
    }

    public static function logTail(array $job, int $lines = 80): string
    {
        if (empty($job['log_file'])) {
            return '';
        }
        $f = storage_path((string) $job['log_file']);
        if (!is_file($f)) {
            return '';
        }
        $all = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return \App\Core\Masker::string(implode("\n", array_slice($all, -$lines)));
    }
}
