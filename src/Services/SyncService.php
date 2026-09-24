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

    /**
     * Modo "bulk" (padrão): lista no SGP as faturas com vencimento na janela relevante
     * (paginado, filtrado por data) e descobre os clientes automaticamente; depois consulta
     * telefone/situação do contrato de parte dos clientes a cada execução (escalonado).
     * Se a listagem em massa falhar, cai para o modo "por cliente".
     */
    public function run(?int $limit = null): array
    {
        if (!$this->client->configured()) {
            return ['status' => 'skipped', 'message' => 'SGP não configurado'];
        }
        $limit ??= max(1, Settings::int('sgp_sync_batch', 200));
        $runId = Db::insert('sync_runs', ['started_at' => now_str(), 'status' => 'running']);
        $tot = ['customers' => 0, 'invoices' => 0, 'events' => 0, 'errors' => 0, 'discovered' => 0];

        $bulk = Settings::get('sgp_sync_mode', 'bulk') === 'bulk' ? $this->bulkTitles($tot) : false;
        if ($bulk) {
            // Enriquecimento: telefone e situação do contrato (clientes nunca consultados primeiro)
            $refreshHours = max(1, Settings::int('sgp_customer_refresh_hours', 3));
            $customers = Db::all("SELECT * FROM customers WHERE document <> ''
                AND (last_synced_at IS NULL OR last_synced_at < DATE_SUB(NOW(), INTERVAL ? HOUR))
                ORDER BY (last_synced_at IS NULL) DESC, last_synced_at ASC LIMIT " . (int) $limit, [$refreshHours]);
        } else {
            $customers = Db::all("SELECT * FROM customers WHERE document <> ''
                ORDER BY (last_synced_at IS NULL) DESC, last_synced_at ASC LIMIT " . (int) $limit);
        }
        foreach ($customers as $c) {
            try {
                $r = $this->syncCustomer($c, !$bulk);
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
        $msg = sprintf('%s%d cliente(s) consultado(s), %d fatura(s) atualizada(s), %d evento(s), %d erro(s)',
            $bulk ? "modo em massa, {$tot['discovered']} cliente(s) novo(s), " : '', $tot['customers'], $tot['invoices'], $tot['events'], $tot['errors']);
        Db::update('sync_runs', [
            'finished_at' => now_str(), 'status' => $status, 'customers_checked' => $tot['customers'],
            'invoices_upserted' => $tot['invoices'], 'events_created' => $tot['events'], 'errors' => $tot['errors'], 'message' => $msg,
        ], 'id = ?', [$runId]);
        Logger::log('sgp', $status === 'ok' ? 'info' : 'warning', "Sincronização SGP concluída: $msg");
        if ($status !== 'error' && ($tot['customers'] > 0 || $bulk)) {
            Settings::set('sgp_last_ok_at', now_str());
        }
        return ['status' => $status, 'message' => $msg] + $tot;
    }

    /** Importa as faturas da janela de vencimento, paginando. @return bool false se a listagem em massa falhou */
    private function bulkTitles(array &$tot): bool
    {
        $from = date('Y-m-d', strtotime('-' . max(1, Settings::int('sgp_window_past_days', 45)) . ' days'));
        $to = date('Y-m-d', strtotime('+' . max(1, Settings::int('sgp_window_future_days', 15)) . ' days'));
        $pageSize = 250;
        $offset = 0;
        $customerCache = [];
        for ($page = 0; $page < 200; $page++) {
            $r = $this->client->titulosPage(['data_vencimento_inicio' => $from, 'data_vencimento_fim' => $to], $offset, $pageSize);
            if (!$r['ok'] || !isset($r['json']['titulos']) || !is_array($r['json']['titulos'])) {
                if ($page === 0) {
                    Logger::warning('sgp', 'Listagem em massa de títulos indisponível; usando consulta por cliente. ' . ($r['error'] ?? ''));
                    return false;
                }
                $tot['errors']++;
                break;
            }
            $items = $r['json']['titulos'];
            foreach ($items as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $doc = preg_replace('/\D/', '', (string) ($raw['clienteCpfcnpj'] ?? ''));
                $parsed = SgpParser::titles([$raw])[0] ?? null;
                if ($parsed === null || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
                    continue;
                }
                try {
                    if (!isset($customerCache[$doc])) {
                        $existed = (bool) Db::value('SELECT id FROM customers WHERE document = ?', [$doc]);
                        $customerCache[$doc] = self::ensureCustomer($doc, (string) ($raw['clienteNome'] ?? ''));
                        $tot['discovered'] += $existed ? 0 : 1;
                    }
                    $customer = $customerCache[$doc];
                    if ($parsed['contract'] !== null) {
                        self::ensureContract($customer, $parsed['contract']);
                    }
                    [$changed, $events] = self::upsertInvoice($customer, $parsed);
                    $tot['invoices'] += $changed ? 1 : 0;
                    $tot['events'] += $events;
                } catch (\Throwable $e) {
                    $tot['errors']++;
                    Logger::error('sgp', 'Erro importando título ' . $parsed['sgp_id'] . ': ' . $e->getMessage());
                }
            }
            $total = (int) ($r['json']['paginacao']['total'] ?? 0);
            $offset += $pageSize;
            if (count($items) < $pageSize || $offset >= $total) {
                break;
            }
            usleep(300000);
        }
        return true;
    }

    /** Cria o contrato (situação desconhecida) se ainda não existir, para vincular faturas. */
    public static function ensureContract(array $customer, string $sgpId): void
    {
        Db::run("INSERT INTO contracts (customer_id, sgp_id, status) VALUES (?, ?, 'other') ON DUPLICATE KEY UPDATE id = id", [$customer['id'], $sgpId]);
    }

    /** @return array{invoices:int,events:int,error:?string} */
    public function syncCustomer(array $customer, bool $withTitles = true): array
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

        $t = $withTitles ? $this->client->titulos($doc) : ['ok' => false, 'json' => null, 'error' => null];
        if (!$withTitles) {
            // modo em massa: as faturas já vieram da listagem geral
        } elseif ($t['ok'] && is_array($t['json'])) {
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
        if ($existing['status'] === 'other' && $c['status'] !== 'other') {
            // Primeira vez que a situação real é conhecida (contrato criado pela listagem de faturas):
            // registra sem disparar evento retroativo.
            Db::update('contracts', $upd + ['status' => $c['status'], 'status_changed_at' => now_str(), 'customer_id' => $customer['id']], 'id = ?', [$existing['id']]);
        } elseif ($c['status'] !== $existing['status'] && $c['status'] !== 'other') {
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
