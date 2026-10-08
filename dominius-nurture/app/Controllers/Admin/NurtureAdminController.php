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
            "SELECT b.id, b.created_at, b.message, b.total, b.channel, b.subject,
                    COALESCE(SUM(i.status = 'sent'), 0) AS sent, COALESCE(SUM(i.status = 'pending'), 0) AS pending,
                    COALESCE(SUM(i.status IN ('failed','skipped')), 0) AS failed
             FROM nurture_broadcasts b LEFT JOIN nurture_broadcast_items i ON i.broadcast_id = b.id
             GROUP BY b.id, b.created_at, b.message, b.total, b.channel, b.subject ORDER BY b.id DESC LIMIT 6"
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

    /** Promoção manual (WhatsApp e/ou e-mail): programa o envio para as pessoas marcadas ou manda só um teste. */
    public function broadcast(Request $request): void
    {
        $message = trim(str_replace("\r", '', (string) ($request->post['message'] ?? '')));
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message) ?? '';
        $action = (string) ($request->post['action'] ?? 'send');
        $channel = (string) ($request->post['channel'] ?? 'whatsapp');
        $channel = in_array($channel, ['whatsapp', 'email', 'both'], true) ? $channel : 'whatsapp';
        $subject = trim(preg_replace('/[\x00-\x1F]+/', ' ', (string) ($request->post['subject'] ?? '')) ?? '');
        $button = trim(preg_replace('/[\x00-\x1F]+/', ' ', (string) ($request->post['button'] ?? '')) ?? '');
        $wantsEmail = $channel !== 'whatsapp';
        $wantsWa = $channel !== 'email';

        if (mb_strlen($message) < 10 || mb_strlen($message) > 1000) {
            flash('error', 'Escreva a mensagem (de 10 a 1000 caracteres).');
            $this->back();
        }
        if ($wantsEmail && (mb_strlen($subject) < 3 || mb_strlen($subject) > 120)) {
            flash('error', 'Escreva o assunto do e-mail (de 3 a 120 caracteres).');
            $this->back();
        }
        if (mb_strlen($button) > 40) {
            $button = mb_substr($button, 0, 40);
        }

        if ($action === 'test') {
            $done = [];
            $errors = [];
            if ($wantsWa) {
                $digits = preg_replace('/\D+/', '', (string) ($request->post['test_phone'] ?? '')) ?? '';
                if ($digits !== '') {
                    $digits = strlen($digits) <= 11 ? '55' . $digits : $digits;
                    $res = \App\Services\NurtureService::sendWhatsapp($digits, \App\Services\NurtureService::broadcastText($message, 'Maria Silva'));
                    $res['ok'] ? $done[] = 'WhatsApp' : $errors[] = 'WhatsApp: ' . (string) $res['error'];
                }
            }
            if ($wantsEmail) {
                $to = trim((string) ($request->post['test_email'] ?? ''));
                if ($to !== '') {
                    $res = filter_var($to, FILTER_VALIDATE_EMAIL)
                        ? \App\Services\NurtureService::sendPromoEmail($to, 'Maria Silva', '[TESTE] ' . $subject, $message, $button, str_repeat('0', 40), null, 'nurture-broadcast-test')
                        : ['ok' => false, 'error' => 'e-mail inválido'];
                    $res['ok'] ? $done[] = 'e-mail' : $errors[] = 'e-mail: ' . (string) $res['error'];
                }
            }
            if (!$done && !$errors) {
                $errors[] = 'informe o número e/ou o e-mail para o teste';
            }
            if ($errors) {
                flash('error', 'Falha no teste: ' . implode('; ', $errors) . ($done ? ' (enviado por ' . implode(' e ', $done) . ')' : ''));
            } else {
                flash('success', 'Teste enviado por ' . implode(' e ', $done) . ' (com o nome "Maria").');
            }
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
        $r = \App\Services\NurtureService::queueBroadcast($message, $ids, $channel, $subject, $button);
        audit('nurture_broadcast', 'nurture', $r['broadcast'], ['people' => $r['people'], 'queued' => $r['queued'], 'channel' => $channel]);
        flash($r['people'] ? 'success' : 'error', $r['people']
            ? "Promoção programada para {$r['people']} pessoa(s)" . ($r['skipped'] ? " ({$r['skipped']} ignorada(s): saíram da lista ou sem contato)" : '') . '. As mensagens saem aos poucos, das 9h às 20h.'
            : 'Nenhuma das pessoas marcadas pode receber (saíram da lista ou estão sem contato).');
        $this->back();
    }

    private function back(): never
    {
        redirect('/admin/sequencia');
    }
}
