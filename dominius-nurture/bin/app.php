<?php
declare(strict_types=1);

/**
 * Ferramentas do aplicativo (CLI):
 *   php bin/app.php status
 *   php bin/app.php sync        (publica sozinho a versão nova compilada no GitHub, se houver)
 *   php bin/app.php publish <arquivo.apk> <numero_da_versao> <nome_da_versao> [--obrigatoria] [--notas="texto"]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\AppService;

$cmd = $argv[1] ?? 'status';

switch ($cmd) {
    case 'status':
        $v = AppService::latestVersion();
        echo 'Clientes do app: ' . (int) Database::value('SELECT COUNT(*) FROM app_clients') . PHP_EOL;
        echo 'Aparelhos: ' . (int) Database::value('SELECT COUNT(*) FROM app_devices') . PHP_EOL;
        echo 'Versão publicada: ' . ($v ? $v['version_name'] . ' (nº ' . $v['version_code'] . ', ' . $v['downloads'] . ' downloads)' : 'nenhuma') . PHP_EOL;
        break;

    case 'publish':
        $path = (string) ($argv[2] ?? '');
        $code = (int) ($argv[3] ?? 0);
        $name = (string) ($argv[4] ?? '');
        $mandatory = false;
        $notes = '';
        foreach (array_slice($argv, 5) as $opt) {
            if ($opt === '--obrigatoria') {
                $mandatory = true;
            } elseif (str_starts_with($opt, '--notas=')) {
                $notes = mb_substr(substr($opt, 8), 0, 1000);
            }
        }
        if (!is_file($path) || $code < 1 || !preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $name)) {
            fwrite(STDERR, "Uso: php bin/app.php publish <arquivo.apk> <numero_da_versao> <nome_da_versao>\n");
            exit(1);
        }
        if ((int) Database::value('SELECT COUNT(*) FROM app_versions WHERE version_code = :c', ['c' => $code]) > 0) {
            fwrite(STDERR, "Já existe a versão nº {$code} no painel. Nada foi alterado.\n");
            exit(2);
        }
        $up = AppService::saveUpload(['name' => basename($path), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)], 'apk');
        if (!$up['ok']) {
            fwrite(STDERR, 'ERRO: ' . ($up['error'] ?? 'falha ao salvar o APK') . PHP_EOL);
            exit(3);
        }
        Database::insert('app_versions', [
            'version_code' => $code,
            'version_name' => $name,
            'file_name' => $up['file'],
            'size_bytes' => $up['size'],
            'sha256' => $up['sha256'],
            'notes' => $notes !== '' ? $notes : null,
            'mandatory' => $mandatory ? 1 : 0,
            'created_at' => Database::now(),
        ]);
        echo "Versão {$name} (nº {$code}) publicada. Link do cliente: " . url('app') . PHP_EOL;
        echo 'sha256: ' . $up['sha256'] . PHP_EOL;
        break;

    case 'sync':
        $repo = 'alpha7tv/dominiusplay-app';
        $get = static function (string $url, int $timeout = 30): ?string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'DominiusPlay-sync', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
            $r = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ($r !== false && $code === 200) ? (string) $r : null;
        };
        $json = $get("https://api.github.com/repos/{$repo}/releases/latest");
        $rel = $json ? json_decode($json, true) : null;
        if (!is_array($rel) || !preg_match('/^build-(\d+)$/', (string) ($rel['tag_name'] ?? ''), $m)) {
            echo "Sem release nova para verificar (ou GitHub indisponível).\n";
            exit(0);
        }
        $code = (int) $m[1];
        $cur = AppService::latestVersion();
        if ($cur && (int) $cur['version_code'] >= $code) {
            echo "Já está na versão mais recente (nº {$cur['version_code']}).\n";
            exit(0);
        }
        $asset = null;
        foreach ((array) ($rel['assets'] ?? []) as $a) {
            if (($a['name'] ?? '') === 'dominiusplay.apk') {
                $asset = $a;
            }
        }
        if (!$asset) {
            fwrite(STDERR, "Release build-{$code} sem o arquivo dominiusplay.apk.\n");
            exit(4);
        }
        $apk = $get("https://github.com/{$repo}/releases/download/build-{$code}/dominiusplay.apk", 300);
        if ($apk === null || strlen($apk) < 100000 || substr($apk, 0, 4) !== "PK\x03\x04" || (int) ($asset['size'] ?? 0) !== strlen($apk)) {
            fwrite(STDERR, "Falha ao baixar/validar o APK da versão {$code}.\n");
            exit(5);
        }
        $tmp = sys_get_temp_dir() . '/dominiusplay-sync-' . $code . '.apk';
        file_put_contents($tmp, $apk);
        $up = AppService::saveUpload(['name' => 'dominiusplay.apk', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], 'apk');
        @unlink($tmp);
        if (!$up['ok']) {
            fwrite(STDERR, 'ERRO: ' . ($up['error'] ?? 'falha ao salvar') . "\n");
            exit(3);
        }
        $name = '1.0.' . $code;
        Database::insert('app_versions', [
            'version_code' => $code, 'version_name' => $name, 'file_name' => $up['file'], 'size_bytes' => $up['size'], 'sha256' => $up['sha256'],
            'notes' => null, 'mandatory' => 0, 'created_at' => Database::now(),
        ]);
        echo "Versão {$name} (nº {$code}) publicada automaticamente.\n";
        break;

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
        exit(1);
}
