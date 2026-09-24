<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Integrations\SgpClient;
use App\Services\HealthService;
use App\Services\Heartbeat;
use App\Services\SyncService;

final class PanelController
{
    private const PER_PAGE = 50;

    public static function dashboard(): never
    {
        Auth::require();
        $stats = Db::one("SELECT
            (SELECT COUNT(*) FROM customers) AS customers,
            (SELECT COUNT(*) FROM invoices WHERE status = 'open') AS open_invoices,
            (SELECT COUNT(*) FROM invoices WHERE status = 'open' AND due_date = CURDATE()) AS due_today,
            (SELECT COUNT(*) FROM invoices WHERE status = 'open' AND due_date < CURDATE()) AS overdue,
            (SELECT COUNT(*) FROM messages WHERE status = 'pending') AS pending,
            (SELECT COUNT(*) FROM messages WHERE sent_at >= CURDATE()) AS sent_today,
            (SELECT COUNT(*) FROM messages WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS failed_24h") ?? [];
        View::page('dashboard', [
            'title' => 'Painel',
            'stats' => array_map('intval', $stats),
            'integrations' => HealthService::integrations(false),
            'worker' => Heartbeat::get('worker'),
            'scheduler' => Heartbeat::get('scheduler'),
            'lastSync' => Db::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1'),
            'recent' => Db::all('SELECT m.*, c.name AS customer_name FROM messages m LEFT JOIN customers c ON c.id = m.customer_id ORDER BY m.id DESC LIMIT 10'),
        ]);
    }

    private static function paginate(string $countSql, array $params): array
    {
        $total = (int) Db::value($countSql, $params);
        $page = max(1, Request::int('p', 1));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        return [$total, $page, $pages, ($page - 1) * self::PER_PAGE];
    }

    public static function customers(): never
    {
        Auth::require();
        $q = Request::str('q');
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $where = '(c.name LIKE ?' . ($digits !== '' ? ' OR c.document LIKE ? OR c.phone LIKE ?' : '') . ')';
            $params[] = "%$q%";
            if ($digits !== '') {
                $params[] = "%$digits%";
                $params[] = "%$digits%";
            }
        }
        [$total, $page, $pages, $offset] = self::paginate("SELECT COUNT(*) FROM customers c WHERE $where", $params);
        $rows = Db::all("SELECT c.*,
                (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id AND i.status = 'open') AS open_invoices,
                (SELECT GROUP_CONCAT(DISTINCT k.status) FROM contracts k WHERE k.customer_id = c.id) AS contract_status
            FROM customers c WHERE $where ORDER BY c.name LIMIT " . self::PER_PAGE . " OFFSET $offset", $params);
        View::page('customers/index', ['title' => 'Clientes', 'rows' => $rows, 'q' => $q, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'sgpConfigured' => SgpClient::fromSettings()->configured()]);
    }

    public static function addCustomer(): never
    {
        Auth::require();
        try {
            $c = SyncService::ensureCustomer(Request::str('document'), Request::str('name'), Request::str('phone') ?: null);
            Audit::log('customer.add', 'customer', (string) $c['id']);
            $sgp = SgpClient::fromSettings();
            if ($sgp->configured()) {
                $r = (new SyncService($sgp))->syncCustomer($c);
                Response::flash($r['error'] ? 'warning' : 'success', $r['error'] ? 'Cliente cadastrado, mas a consulta ao SGP falhou: ' . $r['error'] : 'Cliente cadastrado e sincronizado com o SGP.');
            } else {
                Response::flash('success', 'Cliente cadastrado. Configure o SGP para sincronizar contratos e faturas.');
            }
            Response::redirect('/clientes/' . $c['id']);
        } catch (\InvalidArgumentException $e) {
            Response::flash('error', $e->getMessage());
            Response::redirect('/clientes');
        }
    }

    /** Importação CSV: documento;nome;telefone (cabeçalho opcional). */
    public static function importCustomers(): never
    {
        Auth::require(true);
        $f = $_FILES['csv'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
            Response::flash('error', 'Envie um arquivo CSV de até 5 MB.');
            Response::redirect('/clientes');
        }
        $fh = fopen($f['tmp_name'], 'r');
        $first = (string) fgets($fh);
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        [$ok, $bad] = [0, 0];
        while (($row = fgetcsv($fh, 0, $sep)) !== false) {
            $doc = preg_replace('/\D/', '', (string) ($row[0] ?? ''));
            if (strlen($doc) !== 11 && strlen($doc) !== 14) {
                $bad++;
                continue;
            }
            try {
                SyncService::ensureCustomer($doc, trim((string) ($row[1] ?? '')), trim((string) ($row[2] ?? '')) ?: null);
                $ok++;
            } catch (\Throwable) {
                $bad++;
            }
        }
        fclose($fh);
        Audit::log('customer.import', 'customer', '', ['imported' => $ok, 'invalid' => $bad]);
        Logger::info('app', "Importação CSV: $ok cliente(s), $bad linha(s) ignorada(s)");
        Response::flash('success', "$ok cliente(s) importado(s); $bad linha(s) ignorada(s). A sincronização com o SGP ocorre automaticamente.");
        Response::redirect('/clientes');
    }

    public static function customer(string $id): never
    {
        Auth::require();
        $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $id]);
        if (!$c) {
            View::page('errors/404', ['title' => 'Não encontrado'], 404);
        }
        View::page('customers/show', [
            'title' => $c['name'] ?: 'Cliente',
            'c' => $c,
            'contracts' => Db::all('SELECT * FROM contracts WHERE customer_id = ? ORDER BY id', [$c['id']]),
            'invoices' => Db::all('SELECT * FROM invoices WHERE customer_id = ? ORDER BY due_date DESC LIMIT 50', [$c['id']]),
            'messages' => Db::all('SELECT * FROM messages WHERE customer_id = ? ORDER BY id DESC LIMIT 50', [$c['id']]),
        ]);
    }

    public static function syncCustomer(string $id): never
    {
        Auth::require();
        $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $id]);
        if ($c) {
            $sgp = SgpClient::fromSettings();
            if (!$sgp->configured()) {
                Response::flash('error', 'SGP não configurado.');
            } else {
                $r = (new SyncService($sgp))->syncCustomer($c);
                Response::flash($r['error'] ? 'warning' : 'success', $r['error'] ? 'Falha: ' . $r['error'] : "Sincronizado: {$r['invoices']} fatura(s) atualizada(s), {$r['events']} evento(s).");
            }
        }
        Response::redirect('/clientes/' . (int) $id);
    }

    public static function toggleOptOut(string $id): never
    {
        Auth::require();
        Db::run('UPDATE customers SET opt_out = 1 - opt_out WHERE id = ?', [(int) $id]);
        $v = (int) Db::value('SELECT opt_out FROM customers WHERE id = ?', [(int) $id]);
        if ($v === 1) {
            Db::run("UPDATE messages SET status = 'cancelled', status_reason = 'Cliente bloqueou envios' WHERE customer_id = ? AND status = 'pending'", [(int) $id]);
        }
        Audit::log($v ? 'customer.opt_out' : 'customer.opt_in', 'customer', $id);
        Response::flash('success', $v ? 'Envios bloqueados para este cliente.' : 'Envios liberados para este cliente.');
        Response::redirect('/clientes/' . (int) $id);
    }

    public static function invoices(): never
    {
        Auth::require();
        $status = Request::str('status', 'open');
        $from = Request::str('de');
        $to = Request::str('ate');
        $where = ['1=1'];
        $params = [];
        if (in_array($status, ['open', 'paid', 'cancelled'], true)) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'i.due_date >= ?';
            $params[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'i.due_date <= ?';
            $params[] = $to;
        }
        $w = implode(' AND ', $where);
        [$total, $page, $pages, $offset] = self::paginate("SELECT COUNT(*) FROM invoices i WHERE $w", $params);
        $rows = Db::all("SELECT i.*, c.name AS customer_name, k.sgp_id AS contract_code FROM invoices i
            JOIN customers c ON c.id = i.customer_id LEFT JOIN contracts k ON k.id = i.contract_id
            WHERE $w ORDER BY i.due_date DESC, i.id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset", $params);
        View::page('invoices/index', ['title' => 'Faturas', 'rows' => $rows, 'status' => $status, 'from' => $from, 'to' => $to, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    public static function messages(): never
    {
        Auth::require();
        $status = Request::str('status');
        $event = Request::str('evento');
        $where = ['1=1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if ($event !== '') {
            $where[] = 'm.event = ?';
            $params[] = $event;
        }
        $w = implode(' AND ', $where);
        [$total, $page, $pages, $offset] = self::paginate("SELECT COUNT(*) FROM messages m WHERE $w", $params);
        $rows = Db::all("SELECT m.*, c.name AS customer_name FROM messages m LEFT JOIN customers c ON c.id = m.customer_id
            WHERE $w ORDER BY m.id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset", $params);
        View::page('messages/index', ['title' => 'Mensagens', 'rows' => $rows, 'status' => $status, 'event' => $event, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    public static function message(string $id): never
    {
        Auth::require();
        $m = Db::one('SELECT m.*, c.name AS customer_name, r.name AS rule_name FROM messages m
            LEFT JOIN customers c ON c.id = m.customer_id LEFT JOIN rules r ON r.id = m.rule_id WHERE m.id = ?', [(int) $id]);
        if (!$m) {
            View::page('errors/404', ['title' => 'Não encontrado'], 404);
        }
        View::page('messages/show', ['title' => 'Mensagem #' . $m['id'], 'm' => $m]);
    }

    public static function retryMessage(string $id): never
    {
        Auth::require();
        $n = Db::run("UPDATE messages SET status = 'pending', attempts = 0, available_at = NOW(), last_error = NULL, status_reason = 'Reenvio manual'
            WHERE id = ? AND status IN ('failed','cancelled','skipped')", [(int) $id])->rowCount();
        Audit::log('message.retry', 'message', $id);
        Response::flash($n ? 'success' : 'error', $n ? 'Mensagem devolvida à fila.' : 'Somente mensagens com falha, canceladas ou ignoradas podem ser reenviadas.');
        Response::redirect('/mensagens/' . (int) $id);
    }

    public static function cancelMessage(string $id): never
    {
        Auth::require();
        $n = Db::run("UPDATE messages SET status = 'cancelled', status_reason = 'Cancelada manualmente' WHERE id = ? AND status = 'pending'", [(int) $id])->rowCount();
        Audit::log('message.cancel', 'message', $id);
        Response::flash($n ? 'success' : 'error', $n ? 'Mensagem cancelada.' : 'Somente mensagens pendentes podem ser canceladas.');
        Response::redirect('/mensagens/' . (int) $id);
    }
}
