<?php
declare(strict_types=1);

/**
 * Ferramentas do aplicativo (CLI):
 *   php bin/app.php status
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

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
        exit(1);
}
