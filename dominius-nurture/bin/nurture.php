<?php
declare(strict_types=1);

/**
 * Ferramentas da sequência de nutrição (CLI):
 *   php bin/nurture.php status
 *   php bin/nurture.php preview <1-10> <email>     envia o e-mail N de teste (não grava nada)
 *   php bin/nurture.php whatsapp <telefone>        envia a mensagem de entrada para o telefone
 *   php bin/nurture.php next <email>               adianta o próximo e-mail de uma inscrição para agora
 *   php bin/nurture.php list                       últimas inscrições
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\NurtureService;

$cmd = $argv[1] ?? 'status';

switch ($cmd) {
    case 'status':
        echo 'Sequência: ' . (NurtureService::enabled() ? 'LIGADA' : 'desligada') . PHP_EOL;
        echo 'WhatsApp: ' . (NurtureService::whatsappConfigured() ? 'configurado' : 'NÃO configurado (só e-mails)') . PHP_EOL;
        foreach (Database::fetchAll('SELECT status, COUNT(*) c FROM nurture_subscriptions GROUP BY status') as $r) {
            echo str_pad($r['status'], 14) . $r['c'] . PHP_EOL;
        }
        break;

    case 'list':
        foreach (Database::fetchAll('SELECT id, email, status, step, next_send_at, whatsapp_sent_at, last_error FROM nurture_subscriptions ORDER BY id DESC LIMIT 30') as $r) {
            echo implode(' | ', [$r['id'], $r['email'], $r['status'], 'etapa ' . $r['step'], 'prox ' . ($r['next_send_at'] ?? '-'), 'wa ' . ($r['whatsapp_sent_at'] ? 'ok' : '-'), $r['last_error'] ?? '']) . PHP_EOL;
        }
        break;

    case 'preview':
        $n = (int) ($argv[2] ?? 0);
        $to = (string) ($argv[3] ?? '');
        $res = NurtureService::sendPreview($n, $to);
        echo $res['ok'] ? "Enviado para {$to}\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    case 'whatsapp':
        $phone = (string) ($argv[2] ?? '');
        $res = NurtureService::sendWhatsapp($phone, NurtureService::whatsappText(['name' => 'Teste']));
        echo $res['ok'] ? "WhatsApp enviado\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    case 'next':
        $email = mb_strtolower((string) ($argv[2] ?? ''));
        $n = Database::query("UPDATE nurture_subscriptions SET next_send_at = UTC_TIMESTAMP() WHERE email = :e AND status = 'active'", ['e' => $email])->rowCount();
        echo $n ? "Próximo e-mail de {$email} será enviado no próximo ciclo do worker (até 1 min).\n" : "Nenhuma inscrição ativa para {$email}.\n";
        break;

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
        exit(1);
}
