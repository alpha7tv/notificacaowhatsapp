<?php
declare(strict_types=1);

/**
 * Ferramentas da sequência de nutrição (CLI):
 *   php bin/nurture.php status
 *   php bin/nurture.php preview <1-10> <email>     envia o e-mail N de teste (não grava nada)
 *   php bin/nurture.php whatsapp <telefone>        envia a mensagem de entrada para o telefone
 *   php bin/nurture.php next <email>               adianta o próximo e-mail de uma inscrição para agora
 *   php bin/nurture.php list                       últimas inscrições
 *   php bin/nurture.php followup <telefone> [1-3]    envia o texto do acompanhamento de 4h (sem gravar nada)
 *   php bin/nurture.php stop <email|telefone>        tira a pessoa da sequência (pedido de saída)
 *   php bin/nurture.php testmail <email>           envia um exemplo do e-mail "Seu teste está pronto" (dados fictícios)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Mailer;
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

    case 'followup':
        $phone = (string) ($argv[2] ?? '');
        $variant = isset($argv[3]) ? max(1, (int) $argv[3]) - 1 : 0;
        $text = NurtureService::followupText(['name' => 'Maria Silva', 'id' => 0], $variant);
        $res = NurtureService::sendWhatsapp($phone, $text);
        echo $res['ok'] ? "Acompanhamento (variação " . ($variant + 1) . ") enviado\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    case 'stop':
        $n = NurtureService::stop((string) ($argv[2] ?? ''));
        echo $n ? "{$n} inscrição(ões) encerrada(s).\n" : "Nenhuma inscrição ativa encontrada.\n";
        break;

    case 'testmail':
        $to = (string) ($argv[2] ?? '');
        $message = <<<'TXT'
✅ *NOSSO APLICATIVO OFICIAL (LINK DIRETO):*
*Codigo Downloader* 4116392
*DOWNLOAD:* https://bit.ly/ConnectaP2P
* DOWNLOAD ALTER* http://aftv.news/4116392
✅ *Usuário:* 11112222
✅ *Senha:* 33334444

*TESTAR NO XC IPTV PLAYER*
✅ *Usuário:* 11112222
✅ *Senha:* 33334444
✅ *DNS* http://connectamax.site:80

*TESTAR NO IPTV SMARTERS PLAYER*
✅ *Usuário:* 11112222
✅ *Senha:* 33334444
✅ *DNS* http://vexio.sbn

*TESTAR NO PLAYSIM*
✅ *CODIGO* 634821
✅ *Usuário:* 11112222
✅ *Senha:* 33334444

🟢 *Link (M3U):* http://connectamax.site/get.php?username=11112222&password=33334444&type=m3u_plus&output=mpegts

🟢 *Link Curto (M3U):* http://e.connectamax.site/p/11112222/33334444/m3u

🟡 *Link (HLS):* http://connectamax.site/get.php?username=11112222&password=33334444&type=m3u_plus&output=hls

🟡 *Link Curto (HLS):* http://e.connectamax.site/p/11112222/33334444/hls

🔴 *Link (SSIPTV):* http://e.connectamax.site/p/11112222/33334444/ssiptv

*BAIXAR PLAY SIM* 
BAIXE NA LOJA OFICIAL DE APPS O *DOWLOADER*
_INSIRA UM DESTES CODIGOS ABAIXO PARA BAIXAR APP_
🟠 CODIGO1: 7275096
🟠 CODIGO ALTERNATIVO: 2437721

*PARA BAIXAR PELO NET DOWN*
🟢 CODIGO: 72853
LINK DIRETO: https://dl.ntdev.in/72853

AS V MAIS ANTIGAS SAMSUMG LG BAIXE SMART UP OU SMART STB
📺 *DNS STB NA REDE/ SmartUp:* 185.47.143.6
*TUTORIAL COLOCAR DNS NA REDE* https://www.youtube.com/shorts/sj6vijpelqU

*ASSISTA PELO NAVEGADOR*
📺 *WebPlayer:* http://player.connectamax.lat
✅ *Usuário:* 11112222
✅ *Senha:* 33334444

📦 *Plano:* TESTE COMPLETO C/ADULTOS
💳 *Assinar/Renovar Plano:* https://painel.exemplo.com/#/checkout/EXEMPLO
💵 *Valor do Plano:* R$ 0,00
🗓️ *Vencimento:* 08/10/2026 02:44:32
📶 *Conexões:* 1
TXT;
        $res = Mailer::sendTemplate($to, '[EXEMPLO] Seu teste Dominius Play está pronto', 'test_ready', [
            'name' => 'Maria',
            'hasCredentials' => true,
            'result' => ['username' => '11112222', 'password' => '33334444', 'server' => 'http://connectamax.site', 'expires' => '08/10/2026 02:44:32', 'message' => $message],
        ], 'nurture-preview', null);
        echo $res['ok'] ? "Exemplo enviado para {$to}\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
        exit(1);
}
