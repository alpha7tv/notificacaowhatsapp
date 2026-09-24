<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Db;
use App\Core\RedisConn;

/**
 * Informações do servidor para SISTEMA > SERVIDOR. Somente dados administrativos seguros.
 * O PHP-FPM roda sem privilégios e sem funções de shell; informações que exigem root
 * (Nginx, SSL, backups) vêm de arquivos JSON gravados pelo agente root (deploy/agent.sh).
 */
final class ServerStatus
{
    public static function statusFile(string $name): ?array
    {
        $f = storage_path('status/' . basename($name) . '.json');
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    public static function collect(): array
    {
        $os = '';
        if (is_readable('/etc/os-release')) {
            $ini = @parse_ini_file('/etc/os-release') ?: [];
            $os = (string) ($ini['PRETTY_NAME'] ?? '');
        }
        $uptime = is_readable('/proc/uptime') ? (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0] : null;
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $cpus = is_readable('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + (str_starts_with((string) file_get_contents('/proc/cpuinfo'), 'processor') ? 1 : 0) : null;
        $cpuModel = null;
        if (is_readable('/proc/cpuinfo') && preg_match('/model name\s*:\s*(.+)/', (string) file_get_contents('/proc/cpuinfo'), $m)) {
            $cpuModel = trim($m[1]);
        }
        $mem = null;
        if (is_readable('/proc/meminfo')) {
            $mi = (string) file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $mi, $t);
            preg_match('/MemAvailable:\s+(\d+)/', $mi, $a);
            if ($t && $a) {
                $mem = ['total' => (int) $t[1] * 1024, 'available' => (int) $a[1] * 1024];
            }
        }
        $root = DIRECTORY_SEPARATOR === '/' ? '/' : BASE_PATH;
        $disk = ['total' => (float) @disk_total_space($root), 'free' => (float) @disk_free_space($root)];

        $dbVersion = null;
        try {
            $dbVersion = (string) Db::value('SELECT VERSION()');
        } catch (\Throwable) {
        }
        $redisVersion = null;
        $redisStatus = RedisConn::status();
        if ($redisStatus === 'ok') {
            try {
                $info = RedisConn::get()?->info('server');
                $redisVersion = $info['redis_version'] ?? null;
            } catch (\Throwable) {
            }
        }

        return [
            'hostname' => gethostname() ?: '—',
            'os' => $os ?: php_uname('s') . ' ' . php_uname('r'),
            'uptime' => $uptime,
            'cpus' => $cpus,
            'cpu_model' => $cpuModel,
            'load' => $load,
            'memory' => $mem,
            'disk' => $disk,
            'php' => PHP_VERSION,
            'app_version' => App::version(),
            'release' => App::releaseName(),
            'db_version' => $dbVersion,
            'redis' => $redisStatus,
            'redis_version' => $redisVersion,
            'worker' => Heartbeat::get('worker'),
            'scheduler' => Heartbeat::get('scheduler'),
            'health' => self::statusFile('health'),
            'backup' => self::statusFile('backup'),
            'deploy' => self::statusFile('deploy'),
            'update' => self::statusFile('update'),
        ];
    }
}
