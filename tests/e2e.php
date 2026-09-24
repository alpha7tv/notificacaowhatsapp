<?php
// Teste ponta a ponta (NUNCA rode em produção: apaga dados das tabelas!). Requer:
//   php -S 127.0.0.1:8070 tests/mock/router.php                       (mock SGP + Evolution)
//   FIBERLINK_SERVE=api php -S 127.0.0.1:8061 -t public tests/router.php   (API)
//   FIBERLINK_ENV_FILE=<.env de um banco de TESTE> php tests/e2e.php
declare(strict_types=1);
if (getenv('APP_ENV_ALLOW_E2E') !== '1' && !str_contains((string) getenv('FIBERLINK_ENV_FILE'), 'test')) {
    fwrite(STDERR, "Recusado: aponte FIBERLINK_ENV_FILE para um .env de TESTE.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;
use App\Core\Settings;
use App\Services\{Planner, SyncService, Worker, Scheduler, WebhookService, ReleaseChecklist, HealthService};

$state = __DIR__ . '/mock/state.json';
$mock = fn (array $patch) => file_put_contents($state, json_encode(array_merge(is_file($state) ? json_decode(file_get_contents($state), true) : [], $patch)));
@unlink($state);
$fails = 0;
$check = function (string $name, bool $ok, string $extra = '') use (&$fails) {
    echo str_pad($name, 70, '.') . ($ok ? ' OK' : ' FALHOU') . ($extra ? "  [$extra]" : '') . PHP_EOL;
    $fails += $ok ? 0 : 1;
};
$sentCount = fn () => count(json_decode((string) @file_get_contents($state), true)['sent'] ?? []);

// Limpa dados de execuções anteriores
foreach (['messages', 'invoices', 'contracts', 'customers', 'webhook_events', 'release_checks', 'sync_runs', 'heartbeats', 'locks'] as $t) {
    Db::run("DELETE FROM $t");
}
Settings::set('mode', 'homologation');
Settings::set('sgp_last_ok_at', '');
Settings::set('send_days', '1,2,3,4,5,6,7');
Db::run("UPDATE rules SET send_start='00:00:00', send_end='23:59:59'");

// 1) SGP com token errado
Settings::set('sgp_url', 'http://127.0.0.1:8070'); Settings::set('sgp_app', 'appx'); Settings::set('sgp_token', 'errado');
$r = App\Integrations\SgpClient::fromSettings()->test('12345678909');
$check('SGP: token inválido é detectado', !$r['ok'], (string) $r['error']);
Settings::set('sgp_token', 'tok123');
$check('SGP: token é salvo criptografado no banco', str_starts_with((string) Db::value("SELECT svalue FROM settings WHERE skey='sgp_token'"), 'v1:'));
$r = App\Integrations\SgpClient::fromSettings()->test('12345678909');
$check('SGP: teste de conexão OK', $r['ok']);
try { App\Integrations\SgpClient::fromSettings()->post('/api/ura/fatura2via/', []); $blocked = false; } catch (LogicException) { $blocked = true; }
$check('SGP: endpoint fatura2via bloqueado', $blocked);

// 2) Sincronização inicial (sem eventos retroativos)
Settings::set('sgp_sync_mode', 'bulk');
$r = (new SyncService())->run();
$c = Db::one("SELECT * FROM customers WHERE document = '12345678909'");
$check('Sync em massa: cliente descoberto sozinho pela listagem de faturas', $c !== null, (string) ($r['message'] ?? ''));
$cust = $c ?? ['name' => '', 'phone' => null, 'id' => 0];
$check('Sync: nome e celular (prefere celular) importados', $cust['name'] === 'JOAO DA SILVA' && $cust['phone'] === '5511987654321', $cust['phone'] ?? '');
$check('Sync: contrato ativo e 2 faturas', Db::value("SELECT status FROM contracts WHERE sgp_id='5501'") === 'active' && (int) Db::value('SELECT COUNT(*) FROM invoices') === 2);
$check('Sync: nenhum evento retroativo na importação', (int) Db::value('SELECT COUNT(*) FROM messages') === 0);
$check('Sync: senha do SGP nunca vai para os logs', !Db::value("SELECT COUNT(*) FROM app_logs WHERE message LIKE '%SEGREDO-NAO-LOGAR%' OR context LIKE '%SEGREDO-NAO-LOGAR%'"));
$inv = Db::one("SELECT * FROM invoices WHERE sgp_id='9001'");
$check('Sync: valor "99,90" e data dd/mm/aaaa interpretados', (float) $inv['amount'] === 99.9 && $inv['due_date'] === date('Y-m-d', strtotime('+3 days')));

// 3) Planejamento e idempotência
$n1 = Planner::planDue();
$n2 = Planner::planDue();
$check('Planner: D-3 e D+1 criados', $n1 === 2, "criadas=$n1");
$check('Planner: segunda execução não duplica (idempotência)', $n2 === 0 && (int) Db::value('SELECT COUNT(*) FROM messages') === 2);

// 4) Worker em homologação SEM número de teste -> nada é enviado
Settings::set('evolution_url', 'http://127.0.0.1:8070'); Settings::set('evolution_instance', 'fl'); Settings::set('evolution_apikey', 'evokey');
Settings::set('test_number', '');
(new Worker())->run(60, 10, true);
$check('Homologação sem número de teste: nada enviado', $sentCount() === 0 && (int) Db::value("SELECT COUNT(*) FROM messages WHERE status='skipped'") === 2);

// 5) Homologação com número de teste -> redireciona
Db::run("UPDATE messages SET status='pending', attempts=0");
Settings::set('test_number', '5511955556666');
$mock(['wa_state' => 'connecting']);
(new Worker())->run(60, 10, true);
$check('WhatsApp desconectado: fila aguarda sem gastar mensagens', $sentCount() === 0 && (int) Db::value("SELECT COUNT(*) FROM messages WHERE status='pending' AND attempts=0") === 2);
$mock(['wa_state' => 'open']);
(new Worker())->run(60, 10, true);
$sent = json_decode(file_get_contents($state), true)['sent'];
$allToTest = $sent && !array_filter($sent, fn ($m) => $m['number'] !== '5511955556666');
$check('Homologação: todos os envios vão para o número de teste', count($sent) >= 2 && $allToTest);
$pixCode = (string) Db::value("SELECT pix_code FROM invoices WHERE sgp_id='9001'");
$check('Cobrança: código PIX enviado SOZINHO em mensagem separada', $pixCode !== '' && in_array($pixCode, array_column($sent, 'text'), true));
$check('Cobrança: texto principal não traz o código PIX', !array_filter($sent, fn ($m) => str_contains($m['text'], 'HOMOLOGAÇÃO') && str_contains($m['text'], $pixCode)));
$check('Cobrança: anexos registrados na mensagem', (string) Db::value("SELECT attachments FROM messages m JOIN invoices i ON i.id=m.invoice_id WHERE i.sgp_id='9001' AND m.status='sent' LIMIT 1") !== '');
$check('Homologação: mensagem identifica destino real mascarado', str_contains($sent[0]['text'], 'HOMOLOGAÇÃO') && !str_contains($sent[0]['text'], '5511987654321'));

// 6) Pagamento: transição aberta -> paga gera evento, e cancela lembretes pendentes
Planner::createForInvoice(Db::one("SELECT r.*, t.body AS template_body FROM rules r JOIN templates t ON t.id=r.template_id WHERE r.code='vence_hoje'"), $inv, 'due:manual:test');
$mock(['invoice_status' => 'Pago']);
(new SyncService())->run();
$check('Pagamento: evento "pagamento confirmado" criado', (int) Db::value("SELECT COUNT(*) FROM messages WHERE event='payment_confirmed'") === 1);
$check('Pagamento: lembrete pendente da fatura paga foi cancelado', Db::value("SELECT status FROM messages WHERE idempotency_key='due:manual:test'") === 'cancelled');
(new SyncService())->run();
$check('Pagamento: nova sincronização não duplica o evento', (int) Db::value("SELECT COUNT(*) FROM messages WHERE event='payment_confirmed'") === 1);

// 7) Suspensão e reativação
$mock(['contract_status' => 'Suspenso']);
Db::run('UPDATE customers SET last_synced_at = DATE_SUB(NOW(), INTERVAL 4 HOUR)');
(new SyncService())->run();
$check('Suspensão: evento criado', (int) Db::value("SELECT COUNT(*) FROM messages WHERE event='suspended'") === 1);
$mock(['contract_status' => 'Ativo']);
Db::run('UPDATE customers SET last_synced_at = DATE_SUB(NOW(), INTERVAL 4 HOUR)');
(new SyncService())->run();
$check('Reativação: evento criado', (int) Db::value("SELECT COUNT(*) FROM messages WHERE event='reactivated'") === 1);
Scheduler::reconcileStatus();
$check('Reconciliação: aviso de suspensão pendente cancelado após reativação', Db::value("SELECT status FROM messages WHERE event='suspended'") === 'cancelled');

// 8) Produção: número sem WhatsApp falha sem retentativa; falha temporária gera retentativa
Settings::set('mode', 'production');
Db::run("UPDATE messages SET status='cancelled' WHERE status='pending'");
Db::update('customers', ['phone' => '5511911110000', 'wa_checked_at' => null], 'id = ?', [$c['id']]);
Db::run("INSERT INTO messages (idempotency_key, event, customer_id, destination, body, status, available_at) VALUES ('t:nowa','payment_confirmed',?, '5511911110000','x','pending',NOW())", [$c['id']]);
(new Worker())->run(60, 1, true);
$check('Produção: número sem WhatsApp -> falha definitiva', Db::value("SELECT last_error FROM messages WHERE idempotency_key='t:nowa'") === 'Número não possui WhatsApp');
Db::update('customers', ['phone' => '5511987654321', 'wa_checked_at' => null], 'id = ?', [$c['id']]);
$mock(['fail_next' => 1]);
Db::run("INSERT INTO messages (idempotency_key, event, customer_id, destination, body, status, available_at) VALUES ('t:retry','payment_confirmed',?, '5511987654321','ok','pending',NOW())", [$c['id']]);
(new Worker())->run(60, 1, true);
$m = Db::one("SELECT * FROM messages WHERE idempotency_key='t:retry'");
$check('Produção: erro 503 agenda nova tentativa com backoff', $m['status'] === 'pending' && (int) $m['attempts'] === 1 && strtotime($m['available_at']) > time() + 30);
Db::run("UPDATE messages SET available_at = NOW() WHERE idempotency_key='t:retry'");
(new Worker())->run(60, 1, true);
$last = end(json_decode(file_get_contents($state), true)['sent']);
$check('Produção: reenvio vai para o cliente real', Db::value("SELECT status FROM messages WHERE idempotency_key='t:retry'") === 'sent' && $last['number'] === '5511987654321');
Settings::set('mode', 'homologation');

// 9) Scheduler: rotinas e lock
Db::run('UPDATE scheduled_tasks SET next_run_at = NULL');
$res = Scheduler::runDue();
$check('Scheduler: todas as rotinas executadas com sucesso', !array_diff(array_values($res), ['ok']), json_encode($res));
App\Core\Lock::acquire('task:plan_due', 60);
Scheduler::trigger('plan_due');
$check('Scheduler: rotina com lock ativo não roda em paralelo', Scheduler::runTask('plan_due') === 'skipped');
App\Core\Lock::release('task:plan_due');

// 10) Webhooks via HTTP
$api = 'http://127.0.0.1:8061/api/v1';
$post = function (string $url, array $body, array $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string) $out, true)];
};
[$code] = $post("$api/webhooks/sgp", ['event' => 'x']);
$check('Webhook SGP sem token -> 401', $code === 401);
[$code] = $post("$api/webhooks/sgp?token=errado", ['event' => 'x']);
$check('Webhook SGP token errado -> 401', $code === 401);
$tok = WebhookService::tokenFor('sgp');
$payload = ['event' => 'fatura.paga', 'data' => ['cpfcnpj' => '98765432100', 'nome' => 'MARIA', 'telefone' => '11912345678', 'titulos' => [['id' => 7777, 'valor' => '50,00', 'vencimento' => date('Y-m-d'), 'status' => 'Aberto']]]];
[$code] = $post("$api/webhooks/sgp", $payload, ["X-Webhook-Token: $tok"]);
$check('Webhook SGP válido -> 200 e fatura criada', $code === 200 && Db::value("SELECT status FROM invoices WHERE sgp_id='7777'") !== null, "http=$code");
[$code, $b] = $post("$api/webhooks/sgp", $payload, ["X-Webhook-Token: $tok"]);
$check('Webhook SGP repetido -> duplicate', $code === 200 && ($b['status'] ?? '') === 'duplicate');
$sig = 'sha256=' . hash_hmac('sha256', json_encode(['event' => 'teste']), $tok);
[$code] = $post("$api/webhooks/sgp", ['event' => 'teste'], ["X-Signature: $sig"]);
$check('Webhook SGP com assinatura HMAC aceito', in_array($code, [200, 202], true), "http=$code");
$waTok = WebhookService::tokenFor('whatsapp');
$provId = Db::value("SELECT provider_message_id FROM messages WHERE idempotency_key='t:retry'");
[$code] = $post("$api/webhooks/whatsapp?token=$waTok", ['event' => 'messages.update', 'instance' => 'fl', 'data' => ['keyId' => $provId, 'status' => 'READ']]);
$check('Webhook WhatsApp: status "lida" aplicado', $code === 200 && Db::value("SELECT status FROM messages WHERE idempotency_key='t:retry'") === 'read');
$check('Webhook: payload salvo sem token completo', !Db::value("SELECT COUNT(*) FROM webhook_events WHERE payload LIKE ?", ['%' . $tok . '%']));
$check('Checklist: webhook marcado como configurado', (bool) Db::value("SELECT passed FROM release_checks WHERE code='webhook'"));

// 11) API
$ch = curl_init("$api/health"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $h = json_decode((string) curl_exec($ch), true);
$check('API /api/v1/health responde ok', ($h['status'] ?? '') === 'ok');
[$code, $b] = $post("$api/auth/login", ['email' => 'admin@teste.com', 'password' => 'SenhaTeste123']);
$check('API login devolve JWT', $code === 200 && !empty($b['access_token']));
$ch = curl_init("$api/stats"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($b['access_token'] ?? '')]]);
$st = json_decode((string) curl_exec($ch), true);
$check('API /stats com JWT', isset($st['stats']['customers']));
$ch = curl_init("$api/internal/health"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]); curl_exec($ch);
$check('API interna sem segredo -> 403', curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 403);

// 12) Testes do checklist e liberação
foreach (ReleaseChecklist::TESTS as $code => [$l, $ev]) { Planner::createTest($code, $ev); }
(new Worker())->run(60, 10, true);
$passed = (int) Db::value("SELECT COUNT(*) FROM release_checks WHERE code LIKE 'test_%' AND passed=1");
$check('Checklist: 4 testes de envio aprovados', $passed === 4, "aprovados=$passed");
ReleaseChecklist::validateWhatsappNumber(1);
$items = ReleaseChecklist::items();
$pending = array_column(array_filter($items, fn ($i) => !$i['ok']), 'label');
$check('Checklist: pendências apenas de infraestrutura (SSL/backup/serviços)', !array_diff($pending, ['SSL válido', 'Backup configurado', 'Scheduler funcionando', 'Worker funcionando']), implode(', ', $pending));

echo PHP_EOL . ($fails === 0 ? 'E2E: TUDO OK' : "E2E: $fails FALHA(S)") . PHP_EOL;
exit($fails ? 1 : 0);
