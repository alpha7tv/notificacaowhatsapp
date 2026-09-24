<?php
// Mock de SGP (API URA) + Evolution API para testes locais.
date_default_timezone_set('America/Sao_Paulo');
$state = __DIR__ . '/state.json';
$s = is_file($state) ? json_decode(file_get_contents($state), true) : [];
$s += ['invoice_status' => 'Aberto', 'contract_status' => 'Ativo', 'sent' => [], 'wa_state' => 'open', 'fail_next' => 0];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
header('Content-Type: application/json');
$save = function () use (&$s, $state) { file_put_contents($state, json_encode($s, JSON_PRETTY_PRINT)); };

if (str_starts_with($path, '/api/ura/')) {
    if (($_POST['token'] ?? '') !== 'tok123' || ($_POST['app'] ?? '') !== 'appx') { http_response_code(401); echo json_encode(['msg' => 'Token invalido']); exit; }
}
$due1 = date('d/m/Y', strtotime('+3 days'));
$due2 = date('Y-m-d', strtotime('-1 day'));
switch (true) {
    case $path === '/api/ura/consultacliente/':
        if (($_POST['cpfcnpj'] ?? '') !== '12345678909') { echo json_encode(['contratos' => []]); break; }
        echo json_encode(['contratos' => [[
            'contratoId' => 5501, 'razaoSocial' => 'JOAO DA SILVA', 'cpfCnpj' => '123.456.789-09',
            'telefones' => [['contato' => '(11) 3333-4444'], ['contato' => '(11) 98765-4321']],
            'emails' => [['contato' => 'joao@x.com']], 'servico_plano' => 'FIBRA 500', 'servico_senha' => 'SEGREDO-NAO-LOGAR',
            'contratoStatusDisplay' => $s['contract_status'],
        ]]]);
        break;
    case $path === '/api/ura/titulos/':
        // Formato real do SGP: paginação + dados do cliente em cada título
        $cpf = $_POST['cpfcnpj'] ?? '';
        if ($cpf !== '' && $cpf !== '12345678909') { echo json_encode(['paginacao' => ['offset' => 0, 'limit' => 250, 'parcial' => 0, 'total' => 0], 'titulos' => []]); break; }
        echo json_encode(['paginacao' => ['offset' => 0, 'limit' => 250, 'parcial' => 0, 'total' => 2], 'titulos' => [
            ['id' => 9001, 'clienteNome' => 'JOAO DA SILVA', 'clienteCpfcnpj' => '123.456.789-09', 'clienteContrato' => 5501, 'valor' => '99,90', 'dataVencimento' => $due1, 'status' => $s['invoice_status'],
             'linhaDigitavel' => '00190.00009 01234.567891 23456.789012 1 00000000009990', 'codigoPix' => '00020126580014BR.GOV.BCB.PIX0136abcdef1234567890abcdef12345678905204000053039865405099.905802BR5909FIBERLINK6009SAOPAULO62070503***6304ABCD'],
            ['id' => 9002, 'clienteNome' => 'JOAO DA SILVA', 'clienteCpfcnpj' => '123.456.789-09', 'clienteContrato' => 5501, 'valor' => 120.5, 'vencimento' => $due2, 'status' => 'Vencido'],
        ]]);
        break;
    case str_starts_with($path, '/instance/connectionState/'):
        echo json_encode(['instance' => ['instanceName' => 'fl', 'state' => $s['wa_state']]]);
        break;
    case str_starts_with($path, '/instance/fetchInstances'):
        echo json_encode([['instance' => ['instanceName' => 'fl', 'owner' => '5511900001111@s.whatsapp.net']]]);
        break;
    case str_starts_with($path, '/chat/whatsappNumbers/'):
        echo json_encode(array_map(fn ($n) => ['exists' => !str_ends_with($n, '0000'), 'jid' => $n . '@s.whatsapp.net', 'number' => $n], $body['numbers'] ?? []));
        break;
    case str_starts_with($path, '/message/sendText/'):
        if (($_SERVER['HTTP_APIKEY'] ?? '') !== 'evokey') { http_response_code(401); echo '{"message":"Unauthorized"}'; break; }
        if ($s['fail_next'] > 0) { $s['fail_next']--; $save(); http_response_code(503); echo '{"message":"temporarily down"}'; break; }
        $id = 'MSG' . count($s['sent']);
        $s['sent'][] = ['id' => $id, 'number' => $body['number'], 'text' => $body['text']];
        $save();
        echo json_encode(['key' => ['id' => $id, 'remoteJid' => $body['number'] . '@s.whatsapp.net'], 'status' => 'PENDING']);
        break;
    case str_starts_with($path, '/webhook/set/'):
        $s['webhook'] = $body; $save();
        echo json_encode(['webhook' => ['enabled' => true]]);
        break;
    default:
        http_response_code(404);
        echo '{"error":"mock 404"}';
}
