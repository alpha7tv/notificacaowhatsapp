#!/usr/bin/env bash
# Dominius Play — sequência de nutrição (WhatsApp + 10 e-mails a cada 2 dias, por 20 dias)
# Execute como root no servidor 169.58.48.80:  bash /root/instalar-sequencia.sh
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
[ -d "$APP/app" ] || { echo "Projeto não encontrado em $APP"; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)

echo "== 1/7 Backup =="
DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
mysqldump "$DB" > /root/backup-dominius-db-$STAMP.sql
tar czf /root/backup-dominius-code-$STAMP.tgz -C /var/www dominius-play
echo "Backups: /root/backup-dominius-db-$STAMP.sql e /root/backup-dominius-code-$STAMP.tgz"

echo "== 2/7 Escrevendo arquivos novos =="
mkdir -p database/migrations config bin app/Views/pages app/Views/emails app/Views/partials app/Controllers app/Services
cat > 'database/migrations/2026_10_08_000000_nurture_table.sql' <<'FIM_ARQUIVO_0'
CREATE TABLE IF NOT EXISTS nurture_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_request_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone_e164 VARCHAR(20) NULL,
  token CHAR(40) NOT NULL,
  step TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_send_at DATETIME NULL,
  status ENUM('active','completed','unsubscribed','converted') NOT NULL DEFAULT 'active',
  whatsapp_sent_at DATETIME NULL,
  wa_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  mail_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  consent_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_nurture_test (test_request_id),
  UNIQUE KEY uq_nurture_token (token),
  KEY idx_nurture_due (status, next_send_at),
  KEY idx_nurture_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
FIM_ARQUIVO_0
cat > 'database/migrations/2026_10_08_000001_marketing_consent.sql' <<'FIM_ARQUIVO_1'
ALTER TABLE test_requests ADD COLUMN marketing_consent_at DATETIME NULL AFTER consent_at;
FIM_ARQUIVO_1
cat > 'config/nurture.php' <<'FIM_ARQUIVO_2'
<?php
declare(strict_types=1);

/**
 * Sequência de nutrição: 1 e-mail a cada 2 dias, por 20 dias (10 e-mails).
 * Texto: **negrito**. Marcadores: {first} {min} {max} {days} {per} {prepago_min} {mensal_min}
 * Blocos: p (parágrafo) · list (lista) · plans (prepago|mensalista) · sim (nº de clientes) · note (observação)
 */
return [
    [
        'subject' => '{first}, quanto custa começar a revender IPTV?',
        'pre' => 'Os valores reais dos créditos, sem enrolação.',
        'title' => 'Quanto custa começar, {first}?',
        'blocks' => [
            ['p', 'Você pediu um teste e já viu a plataforma funcionando. Agora a pergunta que importa para quem quer montar uma renda própria: **quanto custa começar a revender?**'],
            ['p', 'O modelo é simples: você compra **créditos**, usa créditos para ativar ou renovar cada cliente por {days} dias e cobra o valor que quiser do seu cliente final. A diferença fica com você.'],
            ['plans', 'prepago'],
            ['note', 'No pré-pago não existe mensalidade: você compra o pacote quando precisar.'],
            ['p', 'Quer ajuda para escolher o pacote certo para o seu momento? Chame a gente no WhatsApp.'],
        ],
        'cta' => 'Tirar dúvidas no WhatsApp',
        'wa' => 'Olá! Recebi o e-mail da Dominius Play e quero entender os valores da revenda.',
        'link' => ['Ver pacotes pré-pagos', 'pre-pago'],
    ],
    [
        'subject' => 'Como funcionam os créditos (explicado em 2 minutos)',
        'pre' => 'Crédito é a moeda da sua operação. Veja como ele é usado.',
        'title' => 'Créditos sem mistério',
        'blocks' => [
            ['p', 'Todo o negócio gira em torno de um conceito: o **crédito**. Entender isso é entender a sua margem.'],
            ['list', [
                'Cada ativação ou renovação de um cliente consome **{per}** do seu saldo.',
                'Cada ativação vale **{days} dias** de acesso para o seu cliente.',
                'Quando o cliente renova no mês seguinte, você usa novos créditos.',
                'Nos planos mensalistas, os créditos são **acumulativos**.',
                'No pré-pago você só compra quando precisar de mais.',
            ]],
            ['p', 'Exemplo: com 50 créditos você ativa 50 clientes por {days} dias. Se você cobra {min} de cada um, é essa conta que mostramos mais adiante.'],
            ['note', '{rule}'],
        ],
        'cta' => 'Quero entender melhor',
        'wa' => 'Olá! Li o e-mail sobre créditos da Dominius Play e tenho uma dúvida.',
    ],
    [
        'subject' => 'Pré-pago ou mensalista: qual combina com você?',
        'pre' => 'Dois jeitos de comprar créditos. Veja qual encaixa no seu momento.',
        'title' => 'Pré-pago ou mensalista?',
        'blocks' => [
            ['p', 'Existem dois jeitos de comprar créditos. **Pré-pago**: você compra pacotes quando precisar, sem mensalidade (a partir de {prepago_min}). **Mensalista**: você paga um valor mensal e recebe os créditos do plano, com melhor custo por crédito (a partir de {mensal_min}/mês).'],
            ['plans', 'mensalista'],
            ['p', '**Regra prática:** está começando e quer testar o mercado? Comece pelo pré-pago. Já tem clientes ou vai crescer rápido? O mensalista costuma sair mais barato por crédito.'],
            ['note', 'O plano Mensalista 100 também permite criar revendas e montar a sua própria equipe.'],
        ],
        'cta' => 'Qual plano é o meu?',
        'wa' => 'Olá! Quero ajuda para escolher entre pré-pago e mensalista na Dominius Play.',
        'link' => ['Ver planos mensalistas', 'mensalista'],
    ],
    [
        'subject' => 'Quanto você pode cobrar do seu cliente final',
        'pre' => 'O preço final é sempre decisão sua. Veja como pensar nisso.',
        'title' => 'Seu preço, sua margem',
        'blocks' => [
            ['p', 'Quem revende define o próprio preço. Para as suas contas, vamos usar como referência uma faixa de **{min} a {max} por cliente ao mês**. O valor real depende da sua região, do seu público e da concorrência.'],
            ['list', [
                'Pesquise quanto cobram na sua cidade antes de definir seu preço.',
                'Use o teste para o cliente conhecer antes de decidir.',
                'Ofereça opções (mensal, trimestral) para fidelizar.',
                'Calcule com antecedência o seu custo por crédito: é ele que manda na sua margem.',
            ]],
            ['p', 'No site há uma **calculadora de margem** para você simular seu cenário com os seus próprios números.'],
        ],
        'cta' => 'Falar sobre minha margem',
        'wa' => 'Olá! Quero conversar sobre preço e margem para revender com a Dominius Play.',
        'link' => ['Abrir a calculadora', 'calculadora'],
    ],
    [
        'subject' => 'Simulação: quanto sobra com 30, 100 e 300 clientes',
        'pre' => 'Conta feita com os planos reais e a sua faixa de preço.',
        'title' => 'Vamos às contas',
        'blocks' => [
            ['p', 'Usando o plano mais barato que atende cada quantidade de clientes e cobrando **{min} por cliente**, estas são as contas:'],
            ['sim', [30, 100, 300]],
            ['note', 'Exemplo ilustrativo. {disclaimer}'],
            ['p', 'Quanto maior a carteira, menor o custo por crédito. Por isso muita gente começa pequeno e vai migrando de plano conforme cresce.'],
        ],
        'cta' => 'Montar minha simulação',
        'wa' => 'Olá! Vi a simulação de lucro da Dominius Play e quero montar a minha.',
    ],
    [
        'subject' => 'O que você tem à disposição para começar',
        'pre' => 'Ferramentas e apoio para sair do zero.',
        'title' => 'Você não começa sozinho',
        'blocks' => [
            ['p', 'Quem entra na revenda encontra um conjunto de recursos para a operação funcionar desde o primeiro dia:'],
            ['list', [
                '**Loja de aplicativos** no site, para o seu cliente instalar e usar.',
                '**Calculadora de margem** para planejar preços e metas.',
                '**Suporte e grupo de informações** nos planos mensalistas (veja os detalhes de cada plano).',
                '**Atendimento no WhatsApp** para tirar dúvidas antes de você comprar.',
            ]],
            ['p', 'Você pode conferir tudo isso no site antes de decidir qualquer coisa.'],
        ],
        'cta' => 'Pedir uma conversa',
        'wa' => 'Olá! Quero saber o que a Dominius Play oferece para quem está começando na revenda.',
        'link' => ['Ver aplicativos', 'aplicativos'],
    ],
    [
        'subject' => 'Como conquistar seus 10 primeiros clientes',
        'pre' => 'Um roteiro simples para começar a vender.',
        'title' => 'Os 10 primeiros clientes',
        'blocks' => [
            ['p', 'O começo é a parte que mais assusta. Um roteiro que costuma funcionar para quem está começando:'],
            ['list', [
                '**Comece pelo seu círculo:** família, amigos, colegas e vizinhos. A confiança vem primeiro.',
                '**Ofereça um teste:** quem experimenta compra com mais segurança.',
                '**Responda rápido:** quem atende depressa vende mais.',
                '**Peça indicação:** cliente satisfeito é a sua melhor propaganda.',
                '**Anote tudo:** controle vencimentos para renovar na data certa.',
            ]],
            ['p', 'Com 10 clientes pagando {min} você já cobre o custo de um pacote pequeno e começa a ver o negócio andar.'],
        ],
        'cta' => 'Quero começar com 10 clientes',
        'wa' => 'Olá! Quero começar a revender com a Dominius Play. Por onde começo?',
    ],
    [
        'subject' => 'As dúvidas que todo futuro revendedor tem',
        'pre' => 'Respostas diretas sobre custo, mensalidade e suporte.',
        'title' => 'Perguntas frequentes',
        'blocks' => [
            ['p', '**Preciso pagar mensalidade?** No pré-pago, não: você compra pacotes quando precisar, a partir de {prepago_min}. No mensalista, sim, a partir de {mensal_min}/mês.'],
            ['p', '**Posso começar pequeno?** Pode. O menor pacote pré-pago é o ponto de partida mais leve para testar o mercado.'],
            ['p', '**E se meu cliente não renovar?** Os créditos só são consumidos quando você ativa ou renova um cliente.'],
            ['p', '**Tenho suporte?** Os planos mensalistas incluem benefícios como suporte e grupo de informações, e você sempre pode falar com a gente no WhatsApp.'],
            ['p', 'Alguma dúvida que não está aqui? É só perguntar.'],
        ],
        'cta' => 'Perguntar no WhatsApp',
        'wa' => 'Olá! Tenho uma dúvida sobre a revenda da Dominius Play.',
    ],
    [
        'subject' => 'Franquia e apontamento: para quem pensa grande',
        'pre' => 'Modelos personalizados para operações maiores.',
        'title' => 'Para quem quer ir além',
        'blocks' => [
            ['p', 'Além dos planos pré-pago e mensalista, a Dominius Play tem modelos para operações maiores:'],
            ['list', [
                '**Franquia personalizada:** modelo sob medida para quem já tem estrutura e quer escalar.',
                '**Apontamento para servidores:** para quem já opera o próprio servidor.',
            ]],
            ['p', 'Esses modelos são montados conforme a sua operação, então o melhor caminho é conversar com a gente para entender o que faz sentido para você.'],
        ],
        'cta' => 'Conversar sobre franquia',
        'wa' => 'Olá! Tenho interesse no modelo de franquia ou apontamento da Dominius Play.',
        'link' => ['Ver franquias', 'franquias'],
    ],
    [
        'subject' => '{first}, vamos montar o seu plano?',
        'pre' => 'Último e-mail da sequência. Resumo e próximos passos.',
        'title' => 'Último e-mail da sequência',
        'blocks' => [
            ['p', 'Este é o último e-mail desta sequência. Resumindo o que vimos nos últimos dias:'],
            ['list', [
                'Você compra créditos e ativa ou renova cada cliente por {days} dias.',
                'Pré-pago a partir de {prepago_min}, sem mensalidade. Mensalista a partir de {mensal_min}/mês.',
                'Você define o preço do seu cliente final (usamos {min} a {max} como referência).',
                'Quanto maior a carteira, menor o custo por crédito.',
            ]],
            ['p', 'Se quiser seguir em frente, me chame no WhatsApp. Eu te ajudo a escolher o plano e a montar os primeiros passos da operação.'],
            ['p', 'Se não for o momento, tudo bem: o seu teste e o site continuam à disposição.'],
        ],
        'cta' => 'Montar meu plano agora',
        'wa' => 'Olá! Quero montar meu plano de revenda na Dominius Play.',
        'link' => ['Ver todos os planos', 'pre-pago'],
    ],
];
FIM_ARQUIVO_2
cat > 'app/Services/NurtureService.php' <<'FIM_ARQUIVO_3'
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;

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
        $res = Mailer::sendTemplate((string) $row['email'], $data['subject'], 'nurture', $data, 'nurture', (int) $row['id']);
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
        return Mailer::sendTemplate($to, '[PREVIEW] ' . $data['subject'], 'nurture', $data, 'nurture-preview', null);
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
                case 'list':
                    $blocks[] = ['type' => 'list', 'items' => array_map($fill, $val)];
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
            'title' => $fill($step['title']),
            'blocks' => $blocks,
            'cta' => $step['cta'],
            'waUrl' => whatsapp_link($waNumber !== '' ? $waNumber : null, $step['wa']),
            'link' => isset($step['link']) ? ['label' => $step['link'][0], 'url' => url($step['link'][1])] : null,
            'unsubUrl' => url('descadastrar/' . ($row['token'] ?? '')),
            'sender' => (string) Env::get('NURTURE_SENDER_NAME', 'Carlos'),
            'name' => $first,
            'stepNumber' => $idx + 1,
        ];
    }

    private static function plans(string $category): array
    {
        $rows = Database::fetchAll(
            'SELECT name, credits, price FROM plans WHERE category = :c AND is_active = 1 ORDER BY sort_order',
            ['c' => $category]
        );
        foreach ($rows as &$r) {
            $r['credits'] = (int) $r['credits'];
            $r['price'] = (float) $r['price'];
            $r['per_credit'] = $r['credits'] > 0 ? $r['price'] / $r['credits'] : 0.0;
        }
        return $rows;
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
FIM_ARQUIVO_3
cat > 'app/Controllers/NurtureController.php' <<'FIM_ARQUIVO_4'
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\NurtureService;

/**
 * Descadastro da sequência de e-mails. O GET só mostra a confirmação (evita descadastro por scanners
 * de link); quem descadastra de fato é o POST.
 */
final class NurtureController extends Controller
{
    public function show(Request $request, string $token): void
    {
        $this->render($token, NurtureService::findByToken($token), false);
    }

    public function confirm(Request $request, string $token): void
    {
        $sub = NurtureService::unsubscribe($token);
        $this->render($token, $sub, $sub !== null);
    }

    private function render(string $token, ?array $sub, bool $done): void
    {
        $this->view('pages.unsubscribe', [
            'sub' => $sub,
            'token' => $token,
            'done' => $done,
            'seo' => $this->seo('Cancelar mensagens', 'Cancelar o recebimento das mensagens.', ['robots' => 'noindex,nofollow']),
        ]);
    }
}
FIM_ARQUIVO_4
cat > 'app/Views/pages/unsubscribe.php' <<'FIM_ARQUIVO_5'
<?php /** @var ?array $sub @var string $token @var bool $done */ ?>
<section class="section-sm">
  <div class="container" style="max-width:640px">
    <div class="state-card">
      <?php if ($done): ?>
        <div class="state-icon"><?= icon('mail-check') ?></div>
        <h3>Pronto, você não receberá mais as nossas mensagens</h3>
        <p class="muted">Cancelamos o envio dos e-mails da sequência para <?= e($sub['email'] ?? '') ?>. O seu teste e o site continuam à disposição.</p>
        <a class="btn btn-primary" href="/">Voltar ao site</a>
      <?php elseif (!$sub): ?>
        <div class="state-icon"><?= icon('info') ?></div>
        <h3>Link inválido</h3>
        <p class="muted">Não encontramos este cadastro. Se precisar de ajuda para parar de receber as mensagens, fale com a nossa equipe.</p>
        <a class="btn btn-primary" href="<?= e(support_link()) ?>">Falar com a equipe</a>
      <?php elseif ($sub['status'] !== 'active'): ?>
        <div class="state-icon"><?= icon('mail-check') ?></div>
        <h3>Você já não está recebendo nossas mensagens</h3>
        <p class="muted">Nenhuma ação é necessária.</p>
        <a class="btn btn-primary" href="/">Voltar ao site</a>
      <?php else: ?>
        <div class="state-icon"><?= icon('shield-check') ?></div>
        <h3>Parar de receber nossas mensagens?</h3>
        <p class="muted">Vamos cancelar o envio dos e-mails da sequência para <?= e($sub['email']) ?>.</p>
        <form method="post" action="/descadastrar/<?= e($token) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-primary" type="submit">Sim, parar de receber</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
FIM_ARQUIVO_5
cat > 'app/Views/emails/nurture.php' <<'FIM_ARQUIVO_6'
<?php
/** @var string $pre @var string $title @var array $blocks @var string $cta @var string $waUrl @var ?array $link @var string $unsubUrl @var string $sender */
$rich = static fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/s', '<strong style="color:#FFFFFF;">$1</strong>', e($s)) ?? e($s);
$th = 'padding:10px 12px;border-bottom:1px solid #1E2842;color:#8E98B0;font-size:12px;text-align:left;font-weight:600;';
$td = 'padding:10px 12px;border-bottom:1px solid #1E2842;color:#EAEEF7;font-size:14px;';
?>
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?= e($pre) ?></span>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#FFFFFF;"><?= e($title) ?></h1>
<?php foreach ($blocks as $b): ?>
  <?php if ($b['type'] === 'p'): ?>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#C3CADB;"><?= $rich($b['text']) ?></p>
  <?php elseif ($b['type'] === 'note'): ?>
<p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#8E98B0;"><?= $rich($b['text']) ?></p>
  <?php elseif ($b['type'] === 'list'): ?>
<ul style="margin:0 0 18px;padding-left:20px;font-size:15px;line-height:1.8;color:#C3CADB;">
    <?php foreach ($b['items'] as $item): ?><li><?= $rich($item) ?></li><?php endforeach; ?>
</ul>
  <?php elseif ($b['type'] === 'plans'): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #1E2842;border-radius:12px;background:#0A0F1C;margin:0 0 18px;border-collapse:separate;overflow:hidden;">
  <tr><th style="<?= $th ?>">Plano</th><th style="<?= $th ?>">Créditos</th><th style="<?= $th ?>">Valor</th><th style="<?= $th ?>">Por crédito</th></tr>
    <?php foreach ($b['rows'] as $r): ?>
  <tr>
    <td style="<?= $td ?>"><?= e($r['name']) ?></td>
    <td style="<?= $td ?>"><?= (int) $r['credits'] ?></td>
    <td style="<?= $td ?>font-weight:700;color:#FFFFFF;"><?= e(money($r['price'])) ?><?= $b['kind'] === 'mensalista' ? '<span style="color:#8E98B0;font-weight:400;">/mês</span>' : '' ?></td>
    <td style="<?= $td ?>"><?= e(money($r['per_credit'])) ?></td>
  </tr>
    <?php endforeach; ?>
</table>
  <?php elseif ($b['type'] === 'sim'): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #1E2842;border-radius:12px;background:#0A0F1C;margin:0 0 18px;border-collapse:separate;overflow:hidden;">
  <tr><th style="<?= $th ?>">Clientes</th><th style="<?= $th ?>">Plano</th><th style="<?= $th ?>">Custo</th><th style="<?= $th ?>">Faturamento</th><th style="<?= $th ?>">Sobra</th></tr>
    <?php foreach ($b['rows'] as $r): ?>
  <tr>
    <td style="<?= $td ?>"><?= (int) $r['clients'] ?></td>
    <td style="<?= $td ?>"><?= e($r['plan']) ?></td>
    <td style="<?= $td ?>"><?= e(money($r['cost'])) ?></td>
    <td style="<?= $td ?>"><?= e(money($r['revenue'])) ?></td>
    <td style="<?= $td ?>font-weight:700;color:#FFC94D;"><?= e(money($r['profit'])) ?></td>
  </tr>
    <?php endforeach; ?>
</table>
  <?php endif; ?>
<?php endforeach; ?>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 22px;"><tr>
  <td style="border-radius:999px;background:#FF7A2F;background-image:linear-gradient(135deg,#FFC94D,#FF7A2F 55%,#FF4D6D);"><a href="<?= e($waUrl) ?>" style="display:inline-block;padding:14px 26px;font-size:14px;font-weight:700;color:#1A0E05;text-decoration:none;text-transform:uppercase;"><?= e($cta) ?></a></td>
  <?php if ($link): ?>
  <td style="width:10px"></td>
  <td style="border-radius:999px;border:1px solid #2A3456;"><a href="<?= e($link['url']) ?>" style="display:inline-block;padding:13px 22px;font-size:14px;font-weight:700;color:#EAEEF7;text-decoration:none;text-transform:uppercase;"><?= e($link['label']) ?></a></td>
  <?php endif; ?>
</tr></table>
<p style="margin:0 0 4px;font-size:15px;color:#C3CADB;">Um abraço,</p>
<p style="margin:0 0 22px;font-size:15px;color:#FFFFFF;font-weight:700;"><?= e($sender) ?> · <?= e(setting('site_name', 'Dominius Play')) ?></p>
<p style="margin:0;padding-top:16px;border-top:1px solid #1E2842;font-size:12px;line-height:1.6;color:#8E98B0;">
  Você recebe este e-mail porque pediu um teste no nosso site e aceitou receber informações sobre revenda.
  <a href="<?= e($unsubUrl) ?>" style="color:#C3CADB;">Parar de receber</a>.
</p>
FIM_ARQUIVO_6
cat > 'app/Views/partials/marketing-consent.php' <<'FIM_ARQUIVO_7'
<div class="field" data-field="marketing">
  <label class="check">
    <input type="checkbox" name="marketing" value="1"<?= old('marketing') ? ' checked' : '' ?>>
    <span>Quero receber mensagens no WhatsApp e e-mails com informações sobre revenda, preços e ofertas da Dominius Play. Posso parar de receber quando quiser.</span>
  </label>
</div>
FIM_ARQUIVO_7
cat > 'bin/nurture.php' <<'FIM_ARQUIVO_8'
<?php
declare(strict_types=1);

/**
 * Ferramentas da sequência de nutrição (CLI):
 *   php bin/nurture.php status
 *   php bin/nurture.php preview <1-10> <email>     envia o e-mail N de teste (não grava nada)
 *   php bin/nurture.php whatsapp <telefone>        envia a mensagem de entrada para o telefone
 *   php bin/nurture.php next <email>               adianta o próximo e-mail de uma inscrição para agora
 *   php bin/nurture.php list                       últimas inscrições
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\NurtureService;

$cmd = $argv[1] ?? 'status';

switch ($cmd) {
    case 'status':
        echo 'Sequência: ' . (NurtureService::enabled() ? 'LIGADA' : 'desligada') . PHP_EOL;
        echo 'WhatsApp: ' . (NurtureService::whatsappConfigured() ? 'configurado' : 'NÃO configurado (só e-mails)') . PHP_EOL;
        foreach (Database::fetchAll('SELECT status, COUNT(*) c FROM nurture_subscriptions GROUP BY status') as $r) {
            echo str_pad($r['status'], 14) . $r['c'] . PHP_EOL;
        }
        break;

    case 'list':
        foreach (Database::fetchAll('SELECT id, email, status, step, next_send_at, whatsapp_sent_at, last_error FROM nurture_subscriptions ORDER BY id DESC LIMIT 30') as $r) {
            echo implode(' | ', [$r['id'], $r['email'], $r['status'], 'etapa ' . $r['step'], 'prox ' . ($r['next_send_at'] ?? '-'), 'wa ' . ($r['whatsapp_sent_at'] ? 'ok' : '-'), $r['last_error'] ?? '']) . PHP_EOL;
        }
        break;

    case 'preview':
        $n = (int) ($argv[2] ?? 0);
        $to = (string) ($argv[3] ?? '');
        $res = NurtureService::sendPreview($n, $to);
        echo $res['ok'] ? "Enviado para {$to}\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    case 'whatsapp':
        $phone = (string) ($argv[2] ?? '');
        $res = NurtureService::sendWhatsapp($phone, NurtureService::whatsappText(['name' => 'Teste']));
        echo $res['ok'] ? "WhatsApp enviado\n" : 'ERRO: ' . ($res['error'] ?? '?') . PHP_EOL;
        break;

    case 'next':
        $email = mb_strtolower((string) ($argv[2] ?? ''));
        $n = Database::query("UPDATE nurture_subscriptions SET next_send_at = UTC_TIMESTAMP() WHERE email = :e AND status = 'active'", ['e' => $email])->rowCount();
        echo $n ? "Próximo e-mail de {$email} será enviado no próximo ciclo do worker (até 1 min).\n" : "Nenhuma inscrição ativa para {$email}.\n";
        break;

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
        exit(1);
}
FIM_ARQUIVO_8
cat > /root/patch-sequencia.py <<'FIM_PATCH'
#!/usr/bin/env python3
"""Aplica as alterações da sequência de nutrição nos arquivos existentes (idempotente, com .bak-nurture)."""
import re
import shutil
import sys

import os
ROOT = os.environ.get('DP_ROOT', '/var/www/dominius-play/')


def read(rel):
    with open(ROOT + rel, encoding='utf-8') as f:
        return f.read()


def write(rel, content):
    shutil.copy2(ROOT + rel, ROOT + rel + '.bak-nurture')
    with open(ROOT + rel, 'w', encoding='utf-8') as f:
        f.write(content)


def once(rel, marker, find, repl, regex=False):
    src = read(rel)
    if marker in src:
        print('[ok, já aplicado] ' + rel + ' :: ' + marker)
        return
    n = len(re.findall(find, src)) if regex else src.count(find)
    if n != 1:
        sys.exit('ERRO: em %s o trecho esperado apareceu %d vez(es) (deveria ser 1): %s' % (rel, n, find[:70]))
    new = re.sub(find, repl, src, count=1) if regex else src.replace(find, repl, 1)
    write(rel, new)
    print('[alterado] ' + rel + ' :: ' + marker)


# 1) Consentimento de marketing gravado junto da solicitação de teste
once('app/Services/TestRequestService.php', 'markMarketing($id, $request)',
     'self::sendVerification($id, false);',
     'self::markMarketing($id, $request);\n        self::sendVerification($id, false);')
once('app/Services/TestRequestService.php', "markMarketing((int) $pending['id']",
     "$sent = self::sendVerification((int) $pending['id'], true);",
     "self::markMarketing((int) $pending['id'], $request);\n            $sent = self::sendVerification((int) $pending['id'], true);")
once('app/Services/TestRequestService.php', 'function markMarketing',
     'private static function recordBlocked(',
     """private static function markMarketing(int $id, Request $request): void
    {
        try {
            if ($request->bool('marketing')) {
                Database::query('UPDATE test_requests SET marketing_consent_at = UTC_TIMESTAMP() WHERE id = :id AND marketing_consent_at IS NULL', ['id' => $id]);
            }
        } catch (\\Throwable $e) {
            Logger::warning('Falha ao registrar consentimento de marketing', ['e' => $e->getMessage()]);
        }
    }

    private static function recordBlocked(""")

# 2) Matrícula na sequência quando o teste é gerado com sucesso
once('app/Services/TestGenerationService.php', 'NurtureService::enroll',
     r"self::deliver\(\$requestId\);(\s*)return 'generated';",
     "self::deliver($requestId);\n            NurtureService::enroll($requestId);\\1return 'generated';",
     regex=True)

# 3) Worker: envios da sequência a cada minuto
once('bin/worker.php', 'NurtureService::runDue',
     '    // 6) Manutenção diária: retenção LGPD e limpeza',
     """    // 5b) Sequência de nutrição (WhatsApp de entrada + e-mails a cada 2 dias)
    $nurtured = \\App\\Services\\NurtureService::runDue(20);
    if ($nurtured) {
        $out("sequência de nutrição: {$nurtured} envio(s)");
    }

    // 6) Manutenção diária: retenção LGPD e limpeza""")

# 4) Rotas de descadastro
once('routes/web.php', 'NurtureController',
     "$router->get('/obrigado', [TestController::class, 'thanks']);",
     "$router->get('/obrigado', [TestController::class, 'thanks']);\n"
     "$router->get('/descadastrar/{token:[a-f0-9]+}', [\\App\\Controllers\\NurtureController::class, 'show']);\n"
     "$router->post('/descadastrar/{token:[a-f0-9]+}', [\\App\\Controllers\\NurtureController::class, 'confirm'], [VerifyCsrf::class]);")

# 5) Checkbox de marketing no formulário do teste
once('app/Views/pages/test-form.php', "partial('marketing-consent')",
     "<?= partial('consent') ?>",
     "<?= partial('consent') ?>\n          <?= partial('marketing-consent') ?>")

print('\nPronto.')
FIM_PATCH

chown $OWNER:$OWNER config/nurture.php bin/nurture.php app/Services/NurtureService.php app/Controllers/NurtureController.php app/Views/pages/unsubscribe.php app/Views/emails/nurture.php app/Views/partials/marketing-consent.php database/migrations/2026_10_08_00000*.sql
chmod 644 config/nurture.php bin/nurture.php app/Services/NurtureService.php app/Controllers/NurtureController.php app/Views/pages/unsubscribe.php app/Views/emails/nurture.php app/Views/partials/marketing-consent.php database/migrations/2026_10_08_00000*.sql

echo "== 3/7 Conferindo sintaxe dos arquivos novos =="
for f in config/nurture.php bin/nurture.php app/Services/NurtureService.php app/Controllers/NurtureController.php app/Views/pages/unsubscribe.php app/Views/emails/nurture.php app/Views/partials/marketing-consent.php; do
  sudo -u $OWNER php -l "$f" | grep -v '^No syntax' || true
  sudo -u $OWNER php -l "$f" >/dev/null || { echo "ERRO de sintaxe em $f — nada foi alterado nos arquivos existentes."; exit 1; }
done

echo "== 4/7 Banco de dados (migração) =="
sudo -u $OWNER php bin/console.php migrate

echo "== 5/7 Alterando os arquivos existentes =="
PATCHED="app/Services/TestRequestService.php app/Services/TestGenerationService.php bin/worker.php routes/web.php app/Views/pages/test-form.php"
python3 /root/patch-sequencia.py
for f in $PATCHED; do
  if ! sudo -u $OWNER php -l "$f" >/dev/null; then
    echo "ERRO de sintaxe em $f depois do patch — restaurando os originais."
    for g in $PATCHED; do [ -f "$g.bak-nurture" ] && cp -a "$g.bak-nurture" "$g"; done
    exit 1
  fi
done

echo "== 6/7 Configuração (.env) =="
add() { grep -q "^$1=" .env || printf '%s=%s\n' "$1" "$2" >> .env; }
add NURTURE_ENABLED 0
add NURTURE_PRICE_MIN 25
add NURTURE_PRICE_MAX 35
add NURTURE_SENDER_NAME Carlos
add NURTURE_WHATSAPP ""
add EVOLUTION_URL http://127.0.0.1:8080
add EVOLUTION_INSTANCE ""
add EVOLUTION_API_KEY ""

echo "== 7/7 Recarregando o PHP =="
systemctl reload php8.1-fpm
sudo -u $OWNER php bin/nurture.php status

echo
echo "PRONTO. A sequência está DESLIGADA (NURTURE_ENABLED=0)."
echo "Para desfazer: restaure os *.bak-nurture ou o backup /root/backup-dominius-code-$STAMP.tgz"
