<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\View;

/**
 * Sequência de nutrição de revendedores: WhatsApp na entrada + 1 e-mail a cada 2 dias (10 e-mails / 20 dias).
 * Só entra quem marcou o consentimento de marketing no formulário do teste.
 * Liga/desliga por NURTURE_ENABLED no .env.
 */
final class NurtureService
{
    public const INTERVAL_DAYS = 2;

    private static ?array $steps = null;

    public static function enabled(): bool
    {
        return (string) Env::get('NURTURE_ENABLED', '0') === '1';
    }

    /** @return array<int,array> */
    public static function steps(): array
    {
        return self::$steps ??= (array) require dirname(__DIR__, 2) . '/config/nurture.php';
    }

    public static function whatsappConfigured(): bool
    {
        return trim((string) Env::get('EVOLUTION_API_KEY', '')) !== '' && trim((string) Env::get('EVOLUTION_INSTANCE', '')) !== '';
    }

    /** Chamado após o teste ser gerado. Nunca lança exceção. */
    public static function enroll(int $testRequestId): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $req = Database::fetch(
                'SELECT id, name, email, phone_e164, marketing_consent_at, status FROM test_requests WHERE id = :id',
                ['id' => $testRequestId]
            );
            if (!$req || $req['marketing_consent_at'] === null || $req['status'] !== 'generated') {
                return;
            }
            $email = mb_strtolower(trim((string) $req['email']));
            if ((int) Database::value('SELECT COUNT(*) FROM nurture_subscriptions WHERE email = :e', ['e' => $email]) > 0) {
                return;
            }
            Database::query(
                "INSERT IGNORE INTO nurture_subscriptions (test_request_id, name, email, phone_e164, token, step, next_send_at, followup_at, status, consent_at, created_at)
                 VALUES (:t, :n, :e, :p, :k, 0, :ns, :fu, 'active', :c, UTC_TIMESTAMP())",
                [
                    't' => $testRequestId,
                    'n' => $req['name'],
                    'e' => $email,
                    'p' => $req['phone_e164'],
                    'k' => bin2hex(random_bytes(20)),
                    'ns' => gmdate('Y-m-d H:i:s', time() + self::INTERVAL_DAYS * 86400),
                    'fu' => gmdate('Y-m-d H:i:s', self::nextWindow(time() + self::followupHours() * 3600)),
                    'c' => $req['marketing_consent_at'],
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Nurture: falha ao matricular', ['e' => $e->getMessage()]);
        }
    }

    /** Executado pelo worker (a cada minuto). Retorna a quantidade de envios feitos. */
    public static function runDue(int $limit = 20): int
    {
        if (!self::enabled()) {
            return self::runBroadcast(time());
        }
        $started = time();
        $count = 0;
        try {
            // 1) WhatsApp de entrada (até 3 tentativas, só nas primeiras 24h)
            if (self::whatsappConfigured()) {
                $rows = Database::fetchAll(
                    "SELECT * FROM nurture_subscriptions WHERE status = 'active' AND whatsapp_sent_at IS NULL AND wa_attempts < 3
                       AND phone_e164 IS NOT NULL AND created_at > :t ORDER BY id LIMIT 10",
                    ['t' => gmdate('Y-m-d H:i:s', time() - 86400)]
                );
                foreach ($rows as $row) {
                    if (time() - $started > 35) {
                        break;
                    }
                    $claim = Database::query(
                        'UPDATE nurture_subscriptions SET wa_attempts = wa_attempts + 1 WHERE id = :id AND whatsapp_sent_at IS NULL AND wa_attempts = :a',
                        ['id' => $row['id'], 'a' => $row['wa_attempts']]
                    )->rowCount();
                    if ($claim !== 1) {
                        continue;
                    }
                    $res = self::sendWhatsapp((string) $row['phone_e164'], self::whatsappText($row));
                    if ($res['ok']) {
                        Database::query('UPDATE nurture_subscriptions SET whatsapp_sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = :id', ['id' => $row['id']]);
                        $count++;
                        sleep(random_int(3, 6));
                    } else {
                        Database::query('UPDATE nurture_subscriptions SET last_error = :e WHERE id = :id', ['id' => $row['id'], 'e' => mb_substr('whatsapp: ' . (string) $res['error'], 0, 250)]);
                    }
                }
            }

            // 1b) Acompanhamento de WhatsApp quando o teste acaba (só em horário comercial)
            if (self::whatsappConfigured()) {
                $fu = Database::fetchAll(
                    "SELECT * FROM nurture_subscriptions WHERE status = 'active' AND followup_sent_at IS NULL AND followup_at IS NOT NULL
                       AND followup_at <= UTC_TIMESTAMP() AND followup_attempts < 3 AND whatsapp_sent_at IS NOT NULL AND phone_e164 IS NOT NULL
                     ORDER BY followup_at LIMIT 10"
                );
                foreach ($fu as $row) {
                    if (time() - $started > 40) {
                        break;
                    }
                    $nextOk = self::nextWindow(time());
                    if ($nextOk > time() + 60) {
                        // fora do horário: empurra para a próxima janela
                        Database::query('UPDATE nurture_subscriptions SET followup_at = :t WHERE id = :id AND followup_sent_at IS NULL', ['id' => $row['id'], 't' => gmdate('Y-m-d H:i:s', $nextOk)]);
                        continue;
                    }
                    $claim = Database::query(
                        'UPDATE nurture_subscriptions SET followup_attempts = followup_attempts + 1 WHERE id = :id AND followup_sent_at IS NULL AND followup_attempts = :a',
                        ['id' => $row['id'], 'a' => $row['followup_attempts']]
                    )->rowCount();
                    if ($claim !== 1) {
                        continue;
                    }
                    $res = self::sendWhatsapp((string) $row['phone_e164'], self::followupText($row));
                    if ($res['ok']) {
                        Database::query('UPDATE nurture_subscriptions SET followup_sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = :id', ['id' => $row['id']]);
                        $count++;
                        sleep(random_int(3, 6));
                    } else {
                        Database::query('UPDATE nurture_subscriptions SET last_error = :e WHERE id = :id', ['id' => $row['id'], 'e' => mb_substr('acompanhamento: ' . (string) $res['error'], 0, 250)]);
                    }
                }
            }

            // 1c) Promoções manuais programadas pelo painel
            $count += self::runBroadcast($started);

            // 2) E-mails vencidos
            $due = Database::fetchAll(
                "SELECT * FROM nurture_subscriptions WHERE status = 'active' AND next_send_at IS NOT NULL AND next_send_at <= UTC_TIMESTAMP()
                 ORDER BY next_send_at LIMIT " . max(1, $limit)
            );
            foreach ($due as $row) {
                if (time() - $started > 45) {
                    break;
                }
                if (self::isConverted((string) $row['email'])) {
                    Database::query("UPDATE nurture_subscriptions SET status = 'converted', next_send_at = NULL WHERE id = :id AND status = 'active'", ['id' => $row['id']]);
                    continue;
                }
                $claim = Database::query(
                    "UPDATE nurture_subscriptions SET next_send_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
                     WHERE id = :id AND status = 'active' AND next_send_at = :old",
                    ['id' => $row['id'], 'old' => $row['next_send_at']]
                )->rowCount();
                if ($claim !== 1) {
                    continue;
                }
                if (self::sendEmailStep($row)) {
                    $count++;
                }
                sleep(1);
            }
        } catch (\Throwable $e) {
            Logger::error('Nurture: falha no processamento', ['e' => $e->getMessage()]);
        }
        return $count;
    }

    private static function isConverted(string $email): bool
    {
        return (int) Database::value("SELECT COUNT(*) FROM leads WHERE LOWER(email) = :e AND status = 'convertido'", ['e' => mb_strtolower($email)]) > 0;
    }

    private static function sendEmailStep(array $row): bool
    {
        $steps = self::steps();
        $idx = (int) $row['step'];
        if (!isset($steps[$idx])) {
            Database::query("UPDATE nurture_subscriptions SET status = 'completed', next_send_at = NULL WHERE id = :id", ['id' => $row['id']]);
            return false;
        }
        $data = self::emailData($row, $idx);
        $res = self::deliverEmail((string) $row['email'], $data, 'nurture', (int) $row['id']);
        if ($res['ok']) {
            self::advance((int) $row['id'], $idx, count($steps));
            return true;
        }
        $attempts = (int) $row['mail_attempts'] + 1;
        if ($attempts >= 5) {
            // desiste deste e-mail e segue a sequência
            self::advance((int) $row['id'], $idx, count($steps));
        } else {
            Database::query('UPDATE nurture_subscriptions SET mail_attempts = :a WHERE id = :id', ['id' => $row['id'], 'a' => $attempts]);
        }
        Database::query('UPDATE nurture_subscriptions SET last_error = :e WHERE id = :id', ['id' => $row['id'], 'e' => mb_substr('email: ' . (string) $res['error'], 0, 250)]);
        return false;
    }

    private static function advance(int $id, int $idx, int $total): void
    {
        $next = $idx + 1;
        if ($next >= $total) {
            Database::query(
                "UPDATE nurture_subscriptions SET step = :s, status = 'completed', next_send_at = NULL, mail_attempts = 0, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id",
                ['id' => $id, 's' => $next]
            );
            return;
        }
        Database::query(
            'UPDATE nurture_subscriptions SET step = :s, next_send_at = :n, mail_attempts = 0, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 's' => $next, 'n' => gmdate('Y-m-d H:i:s', time() + self::INTERVAL_DAYS * 86400)]
        );
    }

    /** Envia o e-mail N (1..10) para um endereço de teste, sem mexer no banco. */
    public static function sendPreview(int $number, string $to): array
    {
        $idx = $number - 1;
        if (!isset(self::steps()[$idx])) {
            return ['ok' => false, 'error' => 'Etapa inexistente.'];
        }
        $row = ['id' => 0, 'name' => 'Teste Preview', 'token' => str_repeat('0', 40)];
        $data = self::emailData($row, $idx);
        $data['subject'] = '[PREVIEW] ' . $data['subject'];
        return self::deliverEmail($to, $data, 'nurture-preview', null);
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        return Database::fetch('SELECT id, name, email, status FROM nurture_subscriptions WHERE token = :t', ['t' => $token]);
    }

    /** Descadastro: devolve a inscrição se existir. */
    public static function unsubscribe(string $token): ?array
    {
        $sub = self::findByToken($token);
        if (!$sub) {
            return null;
        }
        Database::query(
            "UPDATE nurture_subscriptions SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(), next_send_at = NULL, followup_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active'",
            ['id' => $sub['id']]
        );
        return $sub;
    }

    // ------------------------------------------------------------------ acompanhamento (WhatsApp)

    public static function followupHours(): int
    {
        return max(1, (int) Env::get('NURTURE_FOLLOWUP_HOURS', 4));
    }

    /** Menor instante >= $ts dentro da janela de envio (horário de Brasília, padrão 9h às 20h). */
    public static function nextWindow(int $ts): int
    {
        $start = max(0, min(23, (int) Env::get('NURTURE_WINDOW_START', 9)));
        $end = max($start + 1, min(24, (int) Env::get('NURTURE_WINDOW_END', 20)));
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $h = (int) $d->format('G');
        if ($h >= $start && $h < $end) {
            return $ts;
        }
        $target = $h < $start ? $d : $d->modify('+1 day');
        // abre a janela com uma folga aleatória, para não disparar tudo no mesmo minuto
        return $target->setTime($start, random_int(0, 25), 0)->getTimestamp();
    }

    /** Texto do acompanhamento, com a variação escolhida pelo id (ou a pedida em $variant). */
    public static function followupText(array $row, ?int $variant = null): string
    {
        $cfg = (array) require dirname(__DIR__, 2) . '/config/nurture_whatsapp.php';
        $list = $cfg['followups'];
        $i = $variant !== null ? max(0, min(count($list) - 1, $variant)) : ((int) ($row['id'] ?? 0)) % count($list);
        $prepago = self::plans('prepago');
        $tr = [
            '{first}' => explode(' ', trim((string) ($row['name'] ?? '')))[0],
            '{sender}' => (string) Env::get('NURTURE_SENDER_NAME', 'Carlos'),
            '{site}' => (string) setting('site_name', 'Dominius Play'),
            '{hours}' => (string) self::followupHours(),
            '{prepago_min}' => $prepago ? money($prepago[0]['price']) : 'um valor baixo',
        ];
        return strtr($list[$i], $tr) . (string) ($cfg['optout'] ?? '');
    }

    /** Descadastra por e-mail ou telefone (para quem pediu para sair pelo WhatsApp). */
    public static function stop(string $who): int
    {
        $who = trim($who);
        $digits = preg_replace('/\D+/', '', $who) ?? '';
        return Database::query(
            "UPDATE nurture_subscriptions SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(), next_send_at = NULL, followup_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE status = 'active' AND (LOWER(email) = :e OR (:d <> '' AND REPLACE(phone_e164, '+', '') LIKE :dl))",
            ['e' => mb_strtolower($who), 'd' => $digits, 'dl' => '%' . $digits]
        )->rowCount();
    }

    // ------------------------------------------------------------------ promoção manual (WhatsApp e/ou e-mail)

    /**
     * Programa uma promoção para as inscrições escolhidas no painel. O envio sai aos poucos (worker).
     * @param int[] $ids
     * @return array{queued:int,people:int,skipped:int,broadcast:int}
     */
    public static function queueBroadcast(string $message, array $ids, string $channel = 'whatsapp', string $subject = '', string $button = ''): array
    {
        $channel = in_array($channel, ['whatsapp', 'email', 'both'], true) ? $channel : 'whatsapp';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $broadcastId = Database::insert('nurture_broadcasts', [
            'message' => $message,
            'subject' => $subject !== '' ? $subject : null,
            'button' => $button !== '' ? $button : null,
            'channel' => $channel,
            'total' => 0,
            'created_at' => Database::now(),
        ]);
        $queued = 0;
        $people = 0;
        foreach ($ids as $id) {
            $sub = Database::fetch("SELECT id, name, email, phone_e164 FROM nurture_subscriptions WHERE id = :id AND status <> 'unsubscribed'", ['id' => $id]);
            if (!$sub) {
                continue;
            }
            $added = 0;
            foreach (['whatsapp', 'email'] as $ch) {
                if ($channel !== 'both' && $channel !== $ch) {
                    continue;
                }
                $dest = trim((string) ($ch === 'whatsapp' ? $sub['phone_e164'] : $sub['email']));
                if ($dest === '') {
                    continue;
                }
                Database::insert('nurture_broadcast_items', [
                    'broadcast_id' => $broadcastId,
                    'subscription_id' => (int) $sub['id'],
                    'phone_e164' => (string) ($sub['phone_e164'] ?? ''),
                    'name' => $sub['name'],
                    'channel' => $ch,
                    'status' => 'pending',
                    'created_at' => Database::now(),
                ]);
                $added++;
            }
            $queued += $added;
            $people += $added > 0 ? 1 : 0;
        }
        Database::query('UPDATE nurture_broadcasts SET total = :t WHERE id = :id', ['t' => $queued, 'id' => $broadcastId]);
        return ['queued' => $queued, 'people' => $people, 'skipped' => count($ids) - $people, 'broadcast' => $broadcastId];
    }

    private static function placeholders(string $name): array
    {
        return [
            '{first}' => explode(' ', trim($name))[0] ?: 'tudo bem',
            '{sender}' => (string) Env::get('NURTURE_SENDER_NAME', 'Carlos'),
            '{site}' => (string) setting('site_name', 'Dominius Play'),
        ];
    }

    /** Texto final da promoção no WhatsApp (nome e remetente + aviso de saída). */
    public static function broadcastText(string $template, string $name): string
    {
        $cfg = (array) require dirname(__DIR__, 2) . '/config/nurture_whatsapp.php';
        return strtr($template, self::placeholders($name)) . (string) ($cfg['optout'] ?? '');
    }

    /** Envia a promoção por e-mail, com o visual da sequência e o link de descadastro. */
    public static function sendPromoEmail(string $to, string $name, string $subject, string $message, string $button, string $token, ?int $relatedId = null, string $template = 'nurture-broadcast'): array
    {
        $tr = self::placeholders($name);
        $subjectFinal = strtr($subject, $tr);
        $waNumber = trim((string) Env::get('NURTURE_WHATSAPP', ''));
        $data = [
            'subject' => $subjectFinal,
            'title' => $subjectFinal,
            'body' => strtr($message, $tr),
            'cta' => $button !== '' ? $button : 'Falar no WhatsApp',
            'waUrl' => whatsapp_link($waNumber !== '' ? $waNumber : null, 'Olá! Vi a promoção que você me enviou e quero saber mais.'),
            'unsubUrl' => url('descadastrar/' . $token),
            'sender' => (string) Env::get('NURTURE_SENDER_NAME', 'Carlos'),
            'role' => (string) Env::get('NURTURE_SENDER_ROLE', 'Consultor de revendas'),
        ];
        try {
            $html = View::make('emails.promo', $data);
        } catch (\Throwable $e) {
            Logger::error('Nurture: falha ao montar o e-mail de promoção', ['e' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Falha ao montar o e-mail.'];
        }
        $text = str_replace('**', '', $data['body']) . "\n\n" . $data['cta'] . ': ' . $data['waUrl'] . "\n\n" . $data['sender'] . ' - ' . $data['role'] . "\n\nPara parar de receber: " . $data['unsubUrl'];
        return Mailer::send($to, $subjectFinal, $html, $text, $template, 'nurture', $relatedId);
    }

    /** Envia poucas mensagens por minuto, só em horário comercial e até o limite diário de cada canal. */
    private static function runBroadcast(int $started): int
    {
        try {
            if (self::nextWindow(time()) > time() + 60) {
                return 0;
            }
            $dayStart = (new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $sent = 0;
            if (self::whatsappConfigured()) {
                $sent += self::sendBroadcastItems('whatsapp', $started, $dayStart);
            }
            return $sent + self::sendBroadcastItems('email', $started, $dayStart);
        } catch (\Throwable $e) {
            Logger::error('Nurture: falha na promoção manual', ['e' => $e->getMessage()]);
            return 0;
        }
    }

    private static function sendBroadcastItems(string $channel, int $started, string $dayStart): int
    {
        $cap = $channel === 'whatsapp' ? max(1, (int) Env::get('NURTURE_DAILY_CAP', 60)) : max(1, (int) Env::get('NURTURE_EMAIL_DAILY_CAP', 150));
        $perRun = $channel === 'whatsapp' ? 2 : 6;
        $sentToday = (int) Database::value("SELECT COUNT(*) FROM nurture_broadcast_items WHERE channel = :c AND status = 'sent' AND sent_at >= :t", ['c' => $channel, 't' => $dayStart]);
        $room = min($perRun, $cap - $sentToday);
        if ($room <= 0) {
            return 0;
        }
        $items = Database::fetchAll(
            "SELECT i.*, b.message, b.subject, b.button, s.status AS sub_status, s.email AS sub_email, s.token AS sub_token
             FROM nurture_broadcast_items i
             JOIN nurture_broadcasts b ON b.id = i.broadcast_id
             LEFT JOIN nurture_subscriptions s ON s.id = i.subscription_id
             WHERE i.status = 'pending' AND i.channel = :c AND i.attempts < 3 ORDER BY i.id LIMIT " . (int) $room,
            ['c' => $channel]
        );
        $sent = 0;
        foreach ($items as $it) {
            if (time() - $started > 45) {
                break;
            }
            if ($it['sub_status'] === null || $it['sub_status'] === 'unsubscribed') {
                Database::query("UPDATE nurture_broadcast_items SET status = 'skipped', error = 'pessoa saiu da lista' WHERE id = :id AND status = 'pending'", ['id' => $it['id']]);
                continue;
            }
            $claim = Database::query(
                "UPDATE nurture_broadcast_items SET attempts = attempts + 1 WHERE id = :id AND status = 'pending' AND attempts = :a",
                ['id' => $it['id'], 'a' => $it['attempts']]
            )->rowCount();
            if ($claim !== 1) {
                continue;
            }
            if ($channel === 'whatsapp') {
                $res = self::sendWhatsapp((string) $it['phone_e164'], self::broadcastText((string) $it['message'], (string) $it['name']));
            } else {
                $res = self::sendPromoEmail((string) $it['sub_email'], (string) $it['name'], (string) ($it['subject'] ?? ''), (string) $it['message'], (string) ($it['button'] ?? ''), (string) $it['sub_token'], (int) $it['id']);
            }
            if ($res['ok']) {
                Database::query("UPDATE nurture_broadcast_items SET status = 'sent', sent_at = UTC_TIMESTAMP(), error = NULL WHERE id = :id", ['id' => $it['id']]);
                $sent++;
                if ($channel === 'whatsapp') {
                    sleep(random_int(5, 9));
                }
            } else {
                $final = ((int) $it['attempts'] + 1) >= 3;
                Database::query('UPDATE nurture_broadcast_items SET status = :st, error = :e WHERE id = :id', ['id' => $it['id'], 'st' => $final ? 'failed' : 'pending', 'e' => mb_substr((string) $res['error'], 0, 250)]);
            }
        }
        return $sent;
    }

    // ------------------------------------------------------------------ conteúdo

    public static function whatsappText(array $row): string
    {
        $first = explode(' ', trim((string) $row['name']))[0];
        $sender = (string) Env::get('NURTURE_SENDER_NAME', 'Carlos');
        $site = (string) setting('site_name', 'Dominius Play');
        return "Olá, {$first}! Aqui é o {$sender}, da {$site}. 👋\n\n"
            . "Vi que você demonstrou interesse em testar os nossos serviços, e o seu teste já foi enviado para o seu e-mail.\n\n"
            . "Se quiser, eu te explico como funciona a revenda: valores, créditos e quanto dá para lucrar. É só responder esta mensagem.\n\n"
            . 'Se não quiser receber nossas mensagens, é só avisar por aqui.';
    }

    /** @return array{ok:bool,error:?string} */
    public static function sendWhatsapp(string $phone, string $text): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            return ['ok' => false, 'error' => 'Telefone inválido.'];
        }
        $base = rtrim((string) Env::get('EVOLUTION_URL', 'http://127.0.0.1:8080'), '/');
        $url = $base . '/message/sendText/' . rawurlencode((string) Env::get('EVOLUTION_INSTANCE', ''));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . (string) Env::get('EVOLUTION_API_KEY', '')],
            CURLOPT_POSTFIELDS => json_encode(['number' => $digits, 'text' => $text], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Falha de conexão: ' . $err];
        }
        if ($code === 200 || $code === 201) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => self::whatsappError($code, (string) $body)];
    }

    /** Traduz a recusa da Evolution em uma mensagem útil (sem expor dados sensíveis). */
    private static function whatsappError(int $code, string $body): string
    {
        $j = json_decode($body, true);
        $msg = is_array($j) ? ($j['response']['message'] ?? $j['message'] ?? null) : null;
        if (is_array($msg)) {
            foreach ($msg as $m) {
                if (is_array($m) && array_key_exists('exists', $m) && $m['exists'] === false) {
                    return 'este número não tem WhatsApp (confira o DDI e o DDD)';
                }
            }
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        $msg = is_string($msg) ? trim($msg) : '';
        return 'HTTP ' . $code . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '');
    }

    private static function emailData(array $row, int $idx): array
    {
        $step = self::steps()[$idx];
        $first = explode(' ', trim((string) $row['name']))[0];
        $min = (float) Env::get('NURTURE_PRICE_MIN', 25);
        $max = (float) Env::get('NURTURE_PRICE_MAX', 35);
        $plans = [
            'prepago' => self::plans('prepago'),
            'mensalista' => self::plans('mensalista'),
        ];
        $tr = [
            '{first}' => $first,
            '{min}' => money($min),
            '{max}' => money($max),
            '{days}' => (string) (int) setting('credit_days', 30),
            '{per}' => ((int) setting('credits_per_activation', 1)) . ' crédito' . (((int) setting('credits_per_activation', 1)) === 1 ? '' : 's'),
            '{prepago_min}' => $plans['prepago'] ? money($plans['prepago'][0]['price']) : '',
            '{mensal_min}' => $plans['mensalista'] ? money($plans['mensalista'][0]['price']) : '',
            '{rule}' => (string) setting('credit_rule_note', ''),
            '{disclaimer}' => (string) setting('calc_disclaimer', ''),
        ];
        $fill = static fn(string $s): string => strtr($s, $tr);

        $blocks = [];
        foreach ($step['blocks'] as $b) {
            [$type, $val] = $b;
            switch ($type) {
                case 'p':
                case 'note':
                    $text = trim($fill($val));
                    if ($text !== '') {
                        $blocks[] = ['type' => $type, 'text' => $text];
                    }
                    break;
                case 'callout':
                    $text = trim($fill($val));
                    if ($text !== '') {
                        $blocks[] = ['type' => 'callout', 'text' => $text];
                    }
                    break;
                case 'list':
                case 'steps':
                    $blocks[] = ['type' => $type, 'items' => array_map($fill, $val)];
                    break;
                case 'stats':
                    $items = self::stats($val, $plans, $min, $max);
                    if ($items) {
                        $blocks[] = ['type' => 'stats', 'items' => $items];
                    }
                    break;
                case 'plans':
                    if ($plans[$val]) {
                        $blocks[] = ['type' => 'plans', 'kind' => $val, 'rows' => $plans[$val]];
                    }
                    break;
                case 'sim':
                    $rows = self::simulate($val, $min);
                    if ($rows) {
                        $blocks[] = ['type' => 'sim', 'rows' => $rows];
                    }
                    break;
            }
        }

        $waNumber = trim((string) Env::get('NURTURE_WHATSAPP', ''));
        return [
            'subject' => $fill($step['subject']),
            'pre' => $fill($step['pre']),
            'eyebrow' => $step['eyebrow'] ?? 'Revenda IPTV',
            'title' => $fill($step['title']),
            'blocks' => $blocks,
            'cta' => $step['cta'],
            'waUrl' => whatsapp_link($waNumber !== '' ? $waNumber : null, $step['wa']),
            'link' => isset($step['link']) ? ['label' => $step['link'][0], 'url' => url($step['link'][1])] : null,
            'unsubUrl' => url('descadastrar/' . ($row['token'] ?? '')),
            'sender' => (string) Env::get('NURTURE_SENDER_NAME', 'Carlos'),
            'role' => (string) Env::get('NURTURE_SENDER_ROLE', 'Consultor de revendas'),
            'name' => $first,
            'stepNumber' => $idx + 1,
            'stepTotal' => count(self::steps()),
        ];
    }

    private static function plans(string $category): array
    {
        $rows = Database::fetchAll(
            'SELECT name, credits, price, badge FROM plans WHERE category = :c AND is_active = 1 ORDER BY sort_order',
            ['c' => $category]
        );
        $bestKey = null;
        foreach ($rows as $k => &$r) {
            $r['credits'] = (int) $r['credits'];
            $r['price'] = (float) $r['price'];
            $r['per_credit'] = $r['credits'] > 0 ? $r['price'] / $r['credits'] : 0.0;
            $r['tag'] = trim((string) ($r['badge'] ?? ''));
            $r['best'] = false;
            if ($r['credits'] > 0 && ($bestKey === null || $r['per_credit'] < $rows[$bestKey]['per_credit'])) {
                $bestKey = $k;
            }
        }
        unset($r);
        if ($bestKey !== null) {
            $rows[$bestKey]['best'] = true;
            if ($rows[$bestKey]['tag'] === '') {
                $rows[$bestKey]['tag'] = 'Melhor custo';
            }
        }
        return $rows;
    }

    /** Números em destaque do e-mail. */
    private static function stats(array $keys, array $plans, float $min, float $max): array
    {
        $all = array_merge($plans['prepago'], $plans['mensalista']);
        $cheapest = null;
        foreach ($all as $p) {
            if ($p['credits'] > 0 && ($cheapest === null || $p['per_credit'] < $cheapest)) {
                $cheapest = $p['per_credit'];
            }
        }
        $int = static fn(float $v): string => 'R$ ' . number_format($v, 0, ',', '.');
        $out = [];
        foreach ($keys as $k) {
            switch ($k) {
                case 'credit_min':
                    if ($cheapest !== null) {
                        $out[] = ['value' => money($cheapest), 'label' => 'menor custo por crédito'];
                    }
                    break;
                case 'credit_prepago':
                    if ($plans['prepago']) {
                        $m = min(array_map(static fn($p) => $p['credits'] > 0 ? $p['per_credit'] : INF, $plans['prepago']));
                        $out[] = ['value' => money($m), 'label' => 'menor custo por crédito no pré-pago'];
                    }
                    break;
                case 'prepago_min':
                    if ($plans['prepago']) {
                        $out[] = ['value' => $int($plans['prepago'][0]['price']), 'label' => 'pacote inicial, sem mensalidade'];
                    }
                    break;
                case 'mensal_min':
                    if ($plans['mensalista']) {
                        $out[] = ['value' => $int($plans['mensalista'][0]['price']) . '/mês', 'label' => 'plano mensalista a partir de'];
                    }
                    break;
                case 'days':
                    $out[] = ['value' => (int) setting('credit_days', 30) . ' dias', 'label' => 'de acesso a cada ativação'];
                    break;
                case 'range':
                    $out[] = ['value' => $int($min) . '–' . number_format($max, 0, ',', '.'), 'label' => 'referência de preço ao cliente final'];
                    break;
            }
        }
        return $out;
    }

    /** Monta o HTML e a versão em texto e envia. */
    private static function deliverEmail(string $to, array $data, string $template, ?int $relatedId): array
    {
        try {
            $html = View::make('emails.nurture', $data);
        } catch (\Throwable $e) {
            Logger::error('Nurture: falha ao montar o e-mail', ['e' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Falha ao montar o e-mail.'];
        }
        return Mailer::send($to, $data['subject'], $html, self::plainText($data), $template, 'nurture', $relatedId);
    }

    private static function plainText(array $d): string
    {
        $t = [mb_strtoupper($d['eyebrow']), $d['title'], ''];
        $strip = static fn(string $s): string => str_replace('**', '', $s);
        $t[0] = $strip($t[0]);
        $t[1] = $strip($t[1]);
        foreach ($d['blocks'] as $b) {
            switch ($b['type']) {
                case 'p':
                case 'note':
                case 'callout':
                    $t[] = $strip($b['text']);
                    $t[] = '';
                    break;
                case 'list':
                    foreach ($b['items'] as $i) {
                        $t[] = '- ' . $strip($i);
                    }
                    $t[] = '';
                    break;
                case 'steps':
                    foreach ($b['items'] as $n => $i) {
                        $t[] = ($n + 1) . '. ' . $strip($i);
                    }
                    $t[] = '';
                    break;
                case 'stats':
                    foreach ($b['items'] as $i) {
                        $t[] = $i['value'] . ' - ' . $i['label'];
                    }
                    $t[] = '';
                    break;
                case 'plans':
                    foreach ($b['rows'] as $r) {
                        $t[] = $r['name'] . ': ' . money($r['price']) . ($b['kind'] === 'mensalista' ? '/mês' : '') . ' (' . $r['credits'] . ' créditos, ' . money($r['per_credit']) . ' por crédito)';
                    }
                    $t[] = '';
                    break;
                case 'sim':
                    foreach ($b['rows'] as $r) {
                        $t[] = $r['clients'] . ' clientes: sobra ' . money($r['profit']) . ' por mês (plano ' . $r['plan'] . ', custo ' . money($r['cost']) . ', faturamento ' . money($r['revenue']) . ')';
                    }
                    $t[] = '';
                    break;
            }
        }
        $t[] = $d['cta'] . ': ' . $d['waUrl'];
        if (!empty($d['link'])) {
            $t[] = $d['link']['label'] . ': ' . $d['link']['url'];
        }
        $t[] = '';
        $t[] = $d['sender'] . ' - ' . $d['role'];
        $t[] = '';
        $t[] = 'Para parar de receber: ' . $d['unsubUrl'];
        return implode("\n", $t);
    }

    /** Plano mais barato que cobre N clientes e o resultado do exemplo. */
    private static function simulate(array $counts, float $price): array
    {
        $plans = Database::fetchAll(
            "SELECT name, category, credits, price FROM plans WHERE is_active = 1 AND category IN ('prepago','mensalista') ORDER BY price"
        );
        $out = [];
        foreach ($counts as $n) {
            $best = null;
            foreach ($plans as $p) {
                if ((int) $p['credits'] >= $n && ($best === null || (float) $p['price'] < (float) $best['price'])) {
                    $best = $p;
                }
            }
            if ($best === null) {
                continue;
            }
            $revenue = $n * $price;
            $cost = (float) $best['price'];
            $out[] = [
                'clients' => $n,
                'plan' => $best['name'] . ($best['category'] === 'mensalista' ? ' (mensal)' : ''),
                'cost' => $cost,
                'revenue' => $revenue,
                'profit' => $revenue - $cost,
            ];
        }
        return $out;
    }
}
