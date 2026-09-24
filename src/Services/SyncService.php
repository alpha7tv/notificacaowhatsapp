<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Core\Settings;
use App\Integrations\SgpClient;
use App\Integrations\SgpParser;

/**
 * Sincronização com o SGP. Para cada cliente cadastrado (por CPF/CNPJ) consulta contratos
 * e títulos, atualiza o banco local e detecta TRANSIÇÕES (aberta→paga, ativo→suspenso...),
 * que geram eventos/mensagens. Importação inicial nunca gera eventos retroativos.
 */
final class SyncService
{
    public function __construct(private ?SgpClient $client = null)
    {
        $this->client ??= SgpClient::fromSettings();
    }

    public function run(?int $limit = null): array
    {
        if (!$this->client->configured()) {
            return ['status' => 'skipped', 'message' => 'SGP não configurado'];
        }
        $limit ??= max(1, Settings::int('sgp_sync_batch', 200));
        $runId = Db::insert('sync_runs', ['started_at' => now_str(), 'status' => 'running']);
        $customers = Db::all("SELECT * FROM customers WHERE document <> ''
            ORDER BY (last_synced_at IS NULL) DESC, last_synced_at ASC LIMIT " . (int) $limit);
        $tot = ['customers' => 0, 'invoices' => 0, 'events' => 0, 'errors' => 0];
        foreach ($customers as $c) {
            try {
                $r = $this->syncCustomer($c);
                $tot['invoices'] += $r['invoices'];
                $tot['events'] += $r['events'];
                if ($r['error']) {
                    $tot['errors']++;
                }
            } catch (\Throwable $e) {
                $tot['errors']++;
                Logger::error('sgp', 'Erro sincronizando cliente #' . $c['id'] . ': ' . $e->getMessage());
            }
            $tot['customers']++;
            usleep(150000);
        }
        $status = $tot['errors'] === 0 ? 'ok' : ($tot['errors'] < max(1, $tot['customers']) ? 'partial' : 'error');
        $msg = sprintf('%d cliente(s), %d fatura(s), %d evento(s), %d erro(s)', $tot['customers'], $tot['invoices'], $tot['events'], $tot['errors']);
        Db::update('sync_runs', [
            'finished_at' => now_str(), 'status' => $status, 'customers_checked' => $tot['customers'],
            'invoices_upserted' => $tot['invoices'], 'events_created' => $tot['events'], 'errors' => $tot['errors'], 'message' => $msg,
        ], 'id = ?', [$runId]);
        Logger::log('sgp', $status === 'ok' ? 'info' : 'warning', "Sincronização SGP concluída: $msg");
        if ($status !== 'error' && $tot['customers'] > 0) {
            Settings::set('sgp_last_ok_at', now_str());
        }
        return ['status' => $status, 'message' => $msg] + $tot;
    }

    /** @return array{invoices:int,events:int,error:?string} */
    public function syncCustomer(array $customer): array
    {
        $res = ['invoices' => 0, 'events' => 0, 'error' => null];
        $doc = (string) $customer['document'];

        $r = $this->client->consultaCliente($doc);
        if ($r['ok'] && is_array($r['json'])) {
            $p = SgpParser::customer($r['json']);
            $upd = [];
            if ($p['name'] !== '') {
                $upd['name'] = mb_substr($p['name'], 0, 190);
            }
            if ($p['phones']) {
                $upd['phone_raw'] = mb_substr(implode(' / ', $p['phones']), 0, 255);
                $best = Phone::best($p['phones']);
                if ($best && $best !== $customer['phone']) {
                    $upd['phone'] = $best;
                    $upd['wa_exists'] = null;
                    $upd['wa_checked_at'] = null;
                }
            }
            if ($p['email']) {
                $upd['email'] = mb_substr($p['email'], 0, 190);
            }
            if ($upd) {
                Db::update('customers', $upd, 'id = ?', [$customer['id']]);
                $customer = array_merge($customer, $upd);
            }
            foreach ($p['contracts'] as $c) {
                $res['events'] += self::upsertContract($customer, $c);
            }
        } else {
            $res['error'] = $r['error'] ?? 'Falha na consulta do cliente';
        }

        $t = $this->client->titulos($doc);
        if ($t['ok'] && is_array($t['json'])) {
            foreach (SgpParser::titles($t['json']) as $title) {
                [$changed, $events] = self::upsertInvoice($customer, $title);
                $res['invoices'] += $changed ? 1 : 0;
                $res['events'] += $events;
            }
        } elseif (!$res['error']) {
            $res['error'] = $t['error'] ?? 'Falha na consulta de títulos';
        }

        Db::update('customers', ['last_synced_at' => now_str(), 'sync_error' => $res['error'] ? mb_substr($res['error'], 0, 255) : null], 'id = ?', [$customer['id']]);
        return $res;
    }

    /** Cria/atualiza contrato. Retorna quantidade de mensagens de evento criadas. */
    public static function upsertContract(array $customer, array $c): int
    {
        $existing = Db::one('SELECT * FROM contracts WHERE sgp_id = ?', [$c['sgp_id']]);
        if (!$existing) {
            Db::insert('contracts', [
                'customer_id' => $customer['id'], 'sgp_id' => $c['sgp_id'], 'plan' => $c['plan'] ? mb_substr((string) $c['plan'], 0, 190) : null,
                'status' => $c['status'], 'status_raw' => $c['status_raw'], 'status_changed_at' => now_str(),
            ]);
            return 0; // importação inicial: sem eventos retroativos
        }
        $upd = ['plan' => $c['plan'] ? mb_substr((string) $c['plan'], 0, 190) : $existing['plan'], 'status_raw' => $c['status_raw']];
        $events = 0;
        if ($c['status'] !== $existing['status'] && $c['status'] !== 'other') {
            $upd['status'] = $c['status'];
            $upd['status_changed_at'] = now_str();
            Db::update('contracts', $upd, 'id = ?', [$existing['id']]);
            $contract = array_merge($existing, $upd);
            Logger::info('sgp', "Contrato {$c['sgp_id']}: {$existing['status']} → {$c['status']}");
            $event = match (true) {
                $c['status'] === 'suspended' => 'suspended',
                $c['status'] === 'cancelled' => 'cancelled',
                $c['status'] === 'active' && $existing['status'] === 'suspended' => 'reactivated',
                default => null,
            };
            if ($event) {
                $events += Planner::fireEvent($event, $customer, $contract, null, date('Ymd'));
            }
            if ($c['status'] === 'cancelled') {
                Db::run("UPDATE messages SET status = 'cancelled', status_reason = 'Contrato cancelado'
                    WHERE contract_id = ? AND status = 'pending' AND event IN ('before_due','due_today','after_due','suspended')", [$existing['id']]);
            }
        } else {
            Db::update('contracts', $upd, 'id = ?', [$existing['id']]);
        }
        return $events;
    }

    /** @return array{0:bool,1:int} [alterou?, eventos criados] */
    public static function upsertInvoice(array $customer, array $t): array
    {
        $contractId = null;
        if ($t['contract']) {
            $contractId = Db::value('SELECT id FROM contracts WHERE sgp_id = ?', [$t['contract']]);
        }
        $data = [
            'customer_id' => $customer['id'],
            'contract_id' => $contractId ? (int) $contractId : null,
            'amount' => $t['amount'],
            'due_date' => $t['due_date'],
            'status' => $t['status'],
            'status_raw' => $t['status_raw'],
            'paid_at' => $t['paid_at'],
            'paid_amount' => $t['paid_amount'],
            'barcode' => $t['barcode'],
            'pix_code' => $t['pix_code'],
            'pdf_url' => $t['pdf_url'],
        ];
        $existing = Db::one('SELECT * FROM invoices WHERE sgp_id = ?', [$t['sgp_id']]);
        if (!$existing) {
            Db::insert('invoices', $data + ['sgp_id' => $t['sgp_id']]);
            return [true, 0];
        }
        if ($data['status'] === 'paid' && !empty($existing['paid_at'])) {
            $data['paid_at'] = $existing['paid_at'];
        }
        $changed = false;
        foreach ($data as $k => $v) {
            if (in_array($k, ['barcode', 'pix_code', 'pdf_url'], true) && $v === null) {
                continue;
            }
            if (is_float($v) || in_array($k, ['amount', 'paid_amount'], true)) {
                $v = $v === null ? '' : number_format((float) $v, 2, '.', '');
                $existing[$k] = $existing[$k] === null ? '' : number_format((float) $existing[$k], 2, '.', '');
            }
            if ((string) ($existing[$k] ?? '') !== (string) ($v ?? '')) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return [false, 0];
        }
        // Não apaga dados de pagamento já conhecidos se o SGP deixar de enviá-los.
        foreach (['barcode', 'pix_code', 'pdf_url'] as $k) {
            if ($data[$k] === null) {
                unset($data[$k]);
            }
        }
        Db::update('invoices', $data, 'id = ?', [$existing['id']]);
        $events = 0;
        if ($existing['status'] === 'open' && $t['status'] !== 'open') {
            Planner::cancelDueReminders((int) $existing['id'], $t['status'] === 'paid' ? 'Fatura paga' : 'Fatura cancelada');
            if ($t['status'] === 'paid') {
                $invoice = Db::one('SELECT * FROM invoices WHERE id = ?', [$existing['id']]);
                $contract = $invoice['contract_id'] ? Db::one('SELECT * FROM contracts WHERE id = ?', [$invoice['contract_id']]) : null;
                $events += Planner::fireEvent('payment_confirmed', $customer, $contract, $invoice, 'paid');
                Logger::info('sgp', "Pagamento detectado na fatura {$t['sgp_id']}");
            }
        }
        return [true, $events];
    }

    /** Cadastra (ou retorna) cliente pelo CPF/CNPJ. */
    public static function ensureCustomer(string $document, string $name = '', ?string $phone = null): array
    {
        $doc = preg_replace('/\D/', '', $document);
        if (strlen($doc) !== 11 && strlen($doc) !== 14) {
            throw new \InvalidArgumentException('CPF/CNPJ inválido: informe 11 ou 14 dígitos.');
        }
        $existing = Db::one('SELECT * FROM customers WHERE document = ?', [$doc]);
        if ($existing) {
            return $existing;
        }
        $norm = Phone::normalize($phone);
        Db::run('INSERT INTO customers (sgp_id, name, document, phone, phone_raw) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id = id', [
            'doc:' . $doc, mb_substr($name, 0, 190), $doc, $norm, $phone ? mb_substr($phone, 0, 255) : null,
        ]);
        return Db::one('SELECT * FROM customers WHERE document = ?', [$doc]) ?? throw new \RuntimeException('Falha ao cadastrar cliente');
    }
}
