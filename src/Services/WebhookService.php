<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Masker;
use App\Core\Request;
use App\Core\Settings;
use App\Integrations\SgpParser;

/**
 * Recebe webhooks do SGP e do WhatsApp (Evolution API).
 * Autenticação: token derivado do WEBHOOK_SECRET, aceito em
 *   - header "X-Webhook-Token: <token>" ou "Authorization: Bearer <token>"
 *   - query string "?token=<token>"
 *   - assinatura "X-Signature: sha256=<hmac_sha256(corpo, token)>"
 * Toda tentativa é registrada em webhook_events (payload mascarado).
 */
final class WebhookService
{
    private const MAX_BODY = 1048576;

    public static function tokenFor(string $source): string
    {
        $secret = (string) App::env('WEBHOOK_SECRET', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('WEBHOOK_SECRET ausente no .env');
        }
        return substr(hash_hmac('sha256', 'webhook:' . $source, $secret), 0, 40);
    }

    public static function urlFor(string $source): string
    {
        return rtrim((string) App::env('API_URL', ''), '/') . '/api/v1/webhooks/' . $source . '?token=' . self::tokenFor($source);
    }

    public static function authenticate(string $source, string $raw): bool
    {
        $expected = self::tokenFor($source);
        $candidates = [
            Request::header('X-Webhook-Token'),
            preg_replace('/^Bearer\s+/i', '', (string) Request::header('Authorization')),
            is_string($_GET['token'] ?? null) ? $_GET['token'] : null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && hash_equals($expected, $c)) {
                return true;
            }
        }
        $sig = (string) Request::header('X-Signature');
        if ($sig !== '') {
            $calc = 'sha256=' . hash_hmac('sha256', $raw, $expected);
            return hash_equals($calc, $sig) || hash_equals(substr($calc, 7), $sig);
        }
        return false;
    }

    /** @return array{0:int,1:array} [http status, corpo JSON] */
    public static function handle(string $source): array
    {
        $raw = Request::rawBody();
        $ip = Request::ip();
        if (strlen($raw) > self::MAX_BODY) {
            self::record($source, 'rejected', 413, $ip, null, null, 'Payload muito grande');
            return [413, ['error' => 'payload_too_large']];
        }
        if (!self::authenticate($source, $raw)) {
            self::record($source, 'rejected', 401, $ip, null, null, 'Token/assinatura inválidos');
            Logger::warning('webhooks', "Webhook $source rejeitado: autenticação inválida", ['ip' => $ip]);
            return [401, ['error' => 'unauthorized']];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            self::record($source, 'error', 400, $ip, null, mb_substr($raw, 0, 2000), 'JSON inválido');
            return [400, ['error' => 'invalid_json']];
        }

        $eventType = self::eventType($json);
        $dedupe = hash('sha256', (string) (Request::header('X-Event-Id') ?? ($json['event_id'] ?? $json['id'] ?? '')) . '|' . $raw);
        $id = self::record($source, 'received', 200, $ip, $eventType, $json, null, $dedupe);
        if ($id === null) {
            return [200, ['status' => 'duplicate']];
        }

        try {
            $result = $source === 'sgp' ? self::processSgp($json, $eventType) : self::processWhatsapp($json, $eventType);
            $status = $result === 'ignored' ? 'ignored' : 'processed';
            Db::update('webhook_events', ['status' => $status, 'processed_at' => now_str(), 'error' => $result === 'ignored' ? 'Evento não tratado' : null], 'id = ?', [$id]);
            return [$status === 'ignored' ? 202 : 200, ['status' => $status]];
        } catch (\Throwable $e) {
            // Libera a chave de deduplicação para o remetente poder reenviar.
            Db::update('webhook_events', ['status' => 'error', 'http_status' => 500, 'error' => mb_substr($e->getMessage(), 0, 500), 'dedupe_key' => hash('sha256', random_bytes(16))], 'id = ?', [$id]);
            Logger::error('webhooks', "Erro processando webhook $source: " . $e->getMessage());
            return [500, ['error' => 'processing_error']];
        }
    }

    private static function eventType(array $json): ?string
    {
        foreach (['event', 'evento', 'tipo', 'type', 'acao', 'action'] as $k) {
            if (isset($json[$k]) && is_string($json[$k])) {
                return mb_substr($json[$k], 0, 80);
            }
        }
        return null;
    }

    private static function record(string $source, string $status, int $http, string $ip, ?string $event, mixed $payload, ?string $error, ?string $dedupe = null): ?int
    {
        try {
            return Db::insert('webhook_events', [
                'source' => $source, 'dedupe_key' => $dedupe ?? hash('sha256', random_bytes(16)), 'event_type' => $event,
                'status' => $status, 'http_status' => $http, 'ip' => $ip,
                'payload' => $payload === null ? null : mb_substr(is_array($payload) ? Masker::json($payload) : Masker::string((string) $payload), 0, 60000),
                'error' => $error, 'created_at' => now_str(),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /** SGP: aceita títulos/contratos no corpo, ou apenas o CPF/CNPJ (dispara sincronização do cliente). */
    private static function processSgp(array $json, ?string $event): string
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json['dados'] ?? null) ? $json['dados'] : $json);
        $doc = preg_replace('/\D/', '', (string) (SgpParser::first($data, ['cpfcnpj', 'cpfCnpj', 'cpf_cnpj', 'cpf', 'cnpj', 'documento']) ?? ''));
        if (strlen($doc) !== 11 && strlen($doc) !== 14) {
            Logger::info('webhooks', 'Webhook SGP sem CPF/CNPJ identificável', ['event' => $event]);
            return 'ignored';
        }
        $parsedCustomer = SgpParser::customer($data);
        $customer = SyncService::ensureCustomer($doc, $parsedCustomer['name'], $parsedCustomer['phones'][0] ?? null);

        $titles = SgpParser::titles($data);
        $contracts = $parsedCustomer['contracts'];
        $ev = mb_strtolower((string) $event);

        // Evento explícito de pagamento sem campos de status completos
        if ($titles && (str_contains($ev, 'pag') || str_contains($ev, 'paid') || str_contains($ev, 'baixa'))) {
            foreach ($titles as &$t) {
                if ($t['status'] === 'open') {
                    $t['status'] = 'paid';
                    $t['paid_at'] ??= now_str();
                }
            }
            unset($t);
        }
        if ($contracts && !$titles) {
            foreach ($contracts as &$c) {
                if ($c['status'] === 'other') {
                    $c['status'] = match (true) {
                        str_contains($ev, 'susp') || str_contains($ev, 'bloq') => 'suspended',
                        str_contains($ev, 'cancel') => 'cancelled',
                        str_contains($ev, 'reativ') || str_contains($ev, 'ativ') || str_contains($ev, 'libera') => 'active',
                        default => 'other',
                    };
                }
            }
            unset($c);
        }

        if (!$titles && !$contracts) {
            // Só temos o cliente: reconsulta no SGP agora.
            (new SyncService())->syncCustomer($customer);
            return 'processed';
        }
        foreach ($contracts as $c) {
            SyncService::upsertContract($customer, $c);
        }
        foreach ($titles as $t) {
            SyncService::upsertInvoice($customer, $t);
        }
        return 'processed';
    }

    /** Evolution API: atualizações de status de mensagens e da conexão. */
    private static function processWhatsapp(array $json, ?string $event): string
    {
        $ev = strtolower(str_replace('_', '.', (string) $event));
        ReleaseChecklist::markPassed('webhook', 'Webhook do WhatsApp recebido em ' . date('d/m/Y H:i'));

        if ($ev === 'connection.update') {
            $state = $json['data']['state'] ?? null;
            if (is_string($state)) {
                Settings::set('whatsapp_state', $state);
                Settings::set('whatsapp_state_at', now_str());
                Logger::log('whatsapp', $state === 'open' ? 'info' : 'warning', "Conexão do WhatsApp: $state");
            }
            return 'processed';
        }
        if ($ev === 'messages.update') {
            $items = $json['data'] ?? [];
            $items = is_array($items) && array_is_list($items) ? $items : [$items];
            $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $keyId = $it['keyId'] ?? $it['key']['id'] ?? $it['messageId'] ?? null;
                $raw = strtoupper((string) ($it['status'] ?? $it['update']['status'] ?? ''));
                $new = match ($raw) {
                    'DELIVERY_ACK', '3' => 'delivered',
                    'READ', 'PLAYED', '4', '5' => 'read',
                    'SERVER_ACK', '2' => 'sent',
                    default => null,
                };
                if (!is_string($keyId) || $new === null) {
                    continue;
                }
                $msg = Db::one('SELECT id, status FROM messages WHERE provider_message_id = ? LIMIT 1', [$keyId]);
                if ($msg && isset($rank[$msg['status']]) && $rank[$new] > $rank[$msg['status']]) {
                    Db::update('messages', ['status' => $new] + ($new === 'delivered' ? ['delivered_at' => now_str()] : []), 'id = ?', [$msg['id']]);
                }
            }
            return 'processed';
        }
        if (in_array($ev, ['send.message', 'messages.upsert', 'qrcode.updated'], true)) {
            return 'processed';
        }
        return 'ignored';
    }
}
