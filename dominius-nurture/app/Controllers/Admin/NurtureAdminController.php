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
        $broadcasts = Database::fetchAll(
            "SELECT b.id, b.created_at, b.message, b.total,
                    COALESCE(SUM(i.status = 'sent'), 0) AS sent, COALESCE(SUM(i.status = 'pending'), 0) AS pending,
                    COALESCE(SUM(i.status IN ('failed','skipped')), 0) AS failed
             FROM nurture_broadcasts b LEFT JOIN nurture_broadcast_items i ON i.broadcast_id = b.id
             GROUP BY b.id, b.created_at, b.message, b.total ORDER BY b.id DESC LIMIT 6"
        );
        $this->view('nurture/index', [
            'broadcasts' => $broadcasts,
            'dailyCap' => max(1, (int) \App\Core\Env::get('NURTURE_DAILY_CAP', 60)),
            'waReady' => \App\Services\NurtureService::whatsappConfigured(),
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

    /** Promoção manual: programa o envio para as pessoas marcadas, ou manda só um teste para um número. */
    public function broadcast(Request $request): void
    {
        $message = trim(str_replace("\r", '', (string) ($request->post['message'] ?? '')));
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message) ?? '';
        $action = (string) ($request->post['action'] ?? 'send');
        if (mb_strlen($message) < 10 || mb_strlen($message) > 1000) {
            flash('error', 'Escreva a mensagem (de 10 a 1000 caracteres).');
            $this->back();
        }

        if ($action === 'test') {
            $digits = preg_replace('/\D+/', '', (string) ($request->post['test_phone'] ?? '')) ?? '';
            if ($digits !== '' && strlen($digits) <= 11) {
                $digits = '55' . $digits;
            }
            $res = \App\Services\NurtureService::sendWhatsapp($digits, \App\Services\NurtureService::broadcastText($message, 'Maria Silva'));
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Teste enviado (com o nome "Maria").' : 'Falha ao enviar o teste: ' . (string) $res['error']);
            $this->back();
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($request->post['ids'] ?? [])))));
        if (!$ids) {
            flash('error', 'Marque pelo menos uma pessoa na lista.');
            $this->back();
        }
        if (count($ids) > 200) {
            flash('error', 'Escolha no máximo 200 pessoas por promoção.');
            $this->back();
        }
        $r = \App\Services\NurtureService::queueBroadcast($message, $ids);
        audit('nurture_broadcast', 'nurture', $r['broadcast'], ['queued' => $r['queued'], 'skipped' => $r['skipped']]);
        flash($r['queued'] ? 'success' : 'error', $r['queued']
            ? "Promoção programada para {$r['queued']} pessoa(s)" . ($r['skipped'] ? " ({$r['skipped']} ignorada(s): saíram da lista ou sem telefone)" : '') . '. As mensagens saem aos poucos, das 9h às 20h.'
            : 'Nenhuma das pessoas marcadas pode receber (saíram da lista ou estão sem telefone).');
        $this->back();
    }

    private function back(): never
    {
        redirect('/admin/sequencia');
    }
}
