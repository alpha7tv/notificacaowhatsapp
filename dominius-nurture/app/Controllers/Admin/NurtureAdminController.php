<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Services\NurtureService;

/**
 * Painel → Sequência: lista de quem está na sequência de WhatsApp/e-mails e ações de remoção.
 * Restrito a administradores (rotas dentro do grupo AdminOnly).
 */
final class NurtureAdminController extends AdminController
{
    private const STATUSES = ['active', 'completed', 'unsubscribed', 'converted'];

    public function index(Request $request): void
    {
        $q = mb_substr(trim((string) ($request->query['q'] ?? '')), 0, 100);
        $status = (string) ($request->query['status'] ?? '');
        $where = [];
        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $where[] = '(LOWER(email) LIKE :q1 OR LOWER(name) LIKE :q2' . ($digits !== '' ? ' OR REPLACE(phone_e164, \'+\', \'\') LIKE :d' : '') . ')';
            $params['q1'] = '%' . mb_strtolower($q) . '%';
            $params['q2'] = '%' . mb_strtolower($q) . '%';
            if ($digits !== '') {
                $params['d'] = '%' . $digits . '%';
            }
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = :s';
            $params['s'] = $status;
        }
        $rows = Database::fetchAll(
            'SELECT id, name, email, phone_e164, status, step, next_send_at, whatsapp_sent_at, followup_at, followup_sent_at, unsubscribed_at, created_at
             FROM nurture_subscriptions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 300',
            $params
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS c FROM nurture_subscriptions GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $this->view('nurture/index', [
            'rows' => $rows,
            'counts' => $counts,
            'q' => $q,
            'status' => $status,
            'totalSteps' => count(NurtureService::steps()),
            'sequenceOn' => NurtureService::enabled(),
        ], 'Sequência');
    }

    /** Pediu para sair: mantém o registro (prova do descadastro) e para tudo. */
    public function stop(Request $request, string $id): void
    {
        $n = Database::query(
            "UPDATE nurture_subscriptions SET status = 'unsubscribed', unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP()), next_send_at = NULL, followup_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active'",
            ['id' => (int) $id]
        )->rowCount();
        audit('nurture_stop', 'nurture', (int) $id);
        flash($n ? 'success' : 'error', $n ? 'Pronto: essa pessoa não receberá mais mensagens nem e-mails da sequência.' : 'Essa inscrição já não estava ativa.');
        $this->back();
    }

    /** Virou cliente: para a sequência de recrutamento. */
    public function converted(Request $request, string $id): void
    {
        $n = Database::query(
            "UPDATE nurture_subscriptions SET status = 'converted', next_send_at = NULL, followup_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active'",
            ['id' => (int) $id]
        )->rowCount();
        audit('nurture_converted', 'nurture', (int) $id);
        flash($n ? 'success' : 'error', $n ? 'Marcado como cliente. A sequência foi encerrada para essa pessoa.' : 'Essa inscrição já não estava ativa.');
        $this->back();
    }

    /** Exclusão dos dados (pedido LGPD): apaga o registro da sequência. */
    public function delete(Request $request, string $id): void
    {
        $n = Database::delete('nurture_subscriptions', 'id = :id', ['id' => (int) $id]);
        audit('nurture_delete', 'nurture', (int) $id);
        flash($n ? 'success' : 'error', $n ? 'Registro excluído da sequência.' : 'Registro não encontrado.');
        $this->back();
    }

    private function back(): never
    {
        redirect('/admin/sequencia');
    }
}
