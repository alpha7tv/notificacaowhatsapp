<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Transforma regras + dados do SGP em mensagens na fila.
 * Idempotência: cada mensagem tem uma chave única (UNIQUE INDEX uq_messages_idempotency);
 * rodar o planejamento várias vezes NUNCA gera mensagens duplicadas.
 */
final class Planner
{
    public const DUE_EVENTS = ['before_due', 'due_today', 'after_due'];

    /** Próximo horário permitido de envio dentro da janela da regra e dos dias permitidos. */
    public static function nextSendTime(string $start, string $end, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        $from ??= new \DateTimeImmutable('now');
        $days = array_filter(array_map('intval', explode(',', (string) Settings::get('send_days', '1,2,3,4,5,6,7'))));
        if (!$days) {
            $days = [1, 2, 3, 4, 5, 6, 7];
        }
        for ($i = 0; $i < 8; $i++) {
            $day = $from->setTime(0, 0)->modify("+$i day");
            if (!in_array((int) $day->format('N'), $days, true)) {
                continue;
            }
            $s = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $start);
            $e = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $end);
            if ($i === 0) {
                if ($from < $s) {
                    return $s;
                }
                if ($from < $e) {
                    return $from;
                }
                continue;
            }
            return $s;
        }
        return $from;
    }

    public static function inWindow(string $start, string $end, ?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable('now');
        return self::nextSendTime($start, $end, $at)->getTimestamp() <= $at->getTimestamp();
    }

    /** Planeja lembretes de vencimento e cobranças de atraso para hoje. @return int mensagens criadas */
    public static function planDue(): int
    {
        $created = 0;
        $catchup = max(0, min(5, Settings::int('catchup_days', 1)));
        $rules = Db::all("SELECT r.*, t.body AS template_body, t.active AS template_active
            FROM rules r JOIN templates t ON t.id = r.template_id
            WHERE r.active = 1 AND r.event IN ('before_due','due_today','after_due')");
        $today = new \DateTimeImmutable('today');

        foreach ($rules as $rule) {
            if ((int) $rule['template_active'] !== 1) {
                continue;
            }
            $off = (int) $rule['offset_days'];
            [$from, $to] = match ($rule['event']) {
                'before_due' => [$today->modify('+' . max(1, $off - $catchup) . ' day'), $today->modify("+$off day")],
                'due_today' => [$today, $today],
                'after_due' => [$today->modify('-' . ($off + $catchup) . ' day'), $today->modify("-$off day")],
            };
            $invoices = Db::all("SELECT i.* FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                LEFT JOIN contracts k ON k.id = i.contract_id
                WHERE i.status = 'open' AND i.due_date BETWEEN ? AND ?
                  AND (k.id IS NULL OR k.status <> 'cancelled')
                ORDER BY i.due_date, i.id", [$from->format('Y-m-d'), $to->format('Y-m-d')]);

            foreach ($invoices as $inv) {
                $key = sprintf('due:%d:%d:%s', $rule['id'], $inv['id'], $inv['due_date']);
                if (self::createForInvoice($rule, $inv, $key)) {
                    $created++;
                }
            }
        }
        if ($created) {
            Logger::info('scheduler', "Planejamento de vencimentos: $created mensagem(ns) criada(s)");
        }
        return $created;
    }

    public static function createForInvoice(array $rule, array $invoice, string $key): bool
    {
        $customer = Db::one('SELECT * FROM customers WHERE id = ?', [$invoice['customer_id']]);
        $contract = $invoice['contract_id'] ? Db::one('SELECT * FROM contracts WHERE id = ?', [$invoice['contract_id']]) : null;
        return self::create($rule, $customer, $contract, $invoice, $key);
    }

    /** Dispara as regras ativas de um evento (pagamento, suspensão, cancelamento, reativação). */
    public static function fireEvent(string $event, array $customer, ?array $contract, ?array $invoice, string $marker): int
    {
        $rules = Db::all("SELECT r.*, t.body AS template_body, t.active AS template_active
            FROM rules r JOIN templates t ON t.id = r.template_id WHERE r.active = 1 AND r.event = ?", [$event]);
        $n = 0;
        foreach ($rules as $rule) {
            if ((int) $rule['template_active'] !== 1) {
                continue;
            }
            $entity = $invoice ? 'inv' . $invoice['id'] : 'ctr' . ($contract['id'] ?? 0);
            $key = sprintf('evt:%d:%s:%s:%s', $rule['id'], $event, $entity, $marker);
            if (self::create($rule, $customer, $contract, $invoice, $key)) {
                $n++;
            }
        }
        return $n;
    }

    public static function create(array $rule, ?array $customer, ?array $contract, ?array $invoice, string $key): bool
    {
        if (!$customer) {
            return false;
        }
        $status = 'pending';
        $reason = null;
        $phone = (string) ($customer['phone'] ?? '');
        if ((int) $customer['opt_out'] === 1) {
            [$status, $reason] = ['skipped', 'Cliente optou por não receber mensagens'];
        } elseif ($phone === '') {
            [$status, $reason] = ['skipped', 'Cliente sem telefone celular válido'];
        }
        $body = TemplateRenderer::render((string) $rule['template_body'], TemplateRenderer::varsFor($customer, $invoice, $contract));
        $availableAt = self::nextSendTime((string) $rule['send_start'], (string) $rule['send_end']);

        $st = Db::run('INSERT INTO messages (idempotency_key, rule_id, event, customer_id, contract_id, invoice_id, destination, body, status, status_reason, available_at, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id = id', [
            mb_substr($key, 0, 191), $rule['id'], $rule['event'], $customer['id'], $contract['id'] ?? null, $invoice['id'] ?? null,
            $phone, $body, $status, $reason, $availableAt->format('Y-m-d H:i:s'),
        ]);
        return $st->rowCount() === 1;
    }

    /** Cancela lembretes pendentes de uma fatura que deixou de estar em aberto. */
    public static function cancelDueReminders(int $invoiceId, string $reason): int
    {
        return Db::run("UPDATE messages SET status = 'cancelled', status_reason = ?, locked_by = NULL
            WHERE invoice_id = ? AND status = 'pending' AND event IN ('before_due','due_today','after_due')", [$reason, $invoiceId])->rowCount();
    }

    /** Mensagem de teste (checklist de produção): sempre vai para o número de teste. */
    public static function createTest(string $testCode, string $event): array
    {
        $testNumber = Phone::normalize((string) Settings::get('test_number', ''));
        if (!$testNumber) {
            return [false, 'Configure um número de teste válido em Homologação.'];
        }
        $rule = Db::one("SELECT r.*, t.body AS template_body FROM rules r JOIN templates t ON t.id = r.template_id
            WHERE r.event = ? ORDER BY r.active DESC, r.id LIMIT 1", [$event]);
        if (!$rule) {
            return [false, 'Nenhuma regra/modelo encontrado para este evento.'];
        }
        $body = "🧪 *TESTE DE HOMOLOGAÇÃO* — " . event_label($event) . "\n\n"
            . TemplateRenderer::render((string) $rule['template_body'], TemplateRenderer::sampleVars());
        Db::insert('messages', [
            'idempotency_key' => 'test:' . $testCode . ':' . bin2hex(random_bytes(8)),
            'rule_id' => $rule['id'],
            'event' => 'test',
            'destination' => $testNumber,
            'body' => $body,
            'status' => 'pending',
            'is_test' => 1,
            'test_code' => $testCode,
            'available_at' => now_str(),
            'created_at' => now_str(),
        ]);
        return [true, 'Mensagem de teste enviada para a fila. Acompanhe em Mensagens.'];
    }
}
