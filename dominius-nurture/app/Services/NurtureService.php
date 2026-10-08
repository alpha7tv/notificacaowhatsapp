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
                "INSERT IGNORE INTO nurture_subscriptions (test_request_id, name, email, phone_e164, token, step, next_send_at, status, consent_at, created_at)
                 VALUES (:t, :n, :e, :p, :k, 0, :ns, 'active', :c, UTC_TIMESTAMP())",
                [
                    't' => $testRequestId,
                    'n' => $req['name'],
                    'e' => $email,
                    'p' => $req['phone_e164'],
                    'k' => bin2hex(random_bytes(20)),
                    'ns' => gmdate('Y-m-d H:i:s', time() + self::INTERVAL_DAYS * 86400),
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
            return 0;
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
            "UPDATE nurture_subscriptions SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(), next_send_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active'",
            ['id' => $sub['id']]
        );
        return $sub;
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
        return ['ok' => false, 'error' => 'HTTP ' . $code];
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
