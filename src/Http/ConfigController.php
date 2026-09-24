<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Integrations\EvolutionClient;
use App\Integrations\SgpClient;
use App\Integrations\SgpParser;
use App\Services\Phone;
use App\Services\Planner;
use App\Services\ReleaseChecklist;
use App\Services\Scheduler;
use App\Services\TemplateRenderer;
use App\Services\WebhookService;

final class ConfigController
{
    public const EVENTS = ['before_due', 'due_today', 'after_due', 'payment_confirmed', 'suspended', 'cancelled', 'reactivated'];

    // ---------------------------------------------------------------- Regras
    public static function rules(): never
    {
        Auth::require();
        View::page('rules/index', [
            'title' => 'Regras de envio',
            'rules' => Db::all('SELECT r.*, t.name AS template_name FROM rules r JOIN templates t ON t.id = r.template_id ORDER BY FIELD(r.event,' . implode(',', array_map(static fn ($e) => "'$e'", self::EVENTS)) . '), r.offset_days'),
            'templates' => Db::all('SELECT id, name FROM templates ORDER BY name'),
            'edit' => Request::int('editar') ? Db::one('SELECT * FROM rules WHERE id = ?', [Request::int('editar')]) : null,
        ]);
    }

    public static function saveRule(): never
    {
        Auth::require(true);
        $id = Request::int('id');
        $event = Request::str('event');
        $start = Request::str('send_start', '08:00');
        $end = Request::str('send_end', '20:00');
        $data = [
            'name' => mb_substr(Request::str('name'), 0, 120),
            'event' => $event,
            'offset_days' => in_array($event, ['before_due', 'after_due'], true) ? max(0, min(90, Request::int('offset_days'))) : 0,
            'template_id' => Request::int('template_id'),
            'active' => isset($_POST['active']) ? 1 : 0,
            'send_start' => $start,
            'send_end' => $end,
        ];
        $err = match (true) {
            $data['name'] === '' => 'Informe o nome da regra.',
            !in_array($event, self::EVENTS, true) => 'Evento inválido.',
            !Db::value('SELECT id FROM templates WHERE id = ?', [$data['template_id']]) => 'Selecione um modelo.',
            !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end => 'Janela de horário inválida.',
            $event === 'before_due' && $data['offset_days'] < 1 => 'Para "antes do vencimento" informe pelo menos 1 dia.',
            default => null,
        };
        if ($err) {
            Response::flash('error', $err);
            Response::redirect('/regras' . ($id ? '?editar=' . $id : ''));
        }
        if ($id) {
            Db::update('rules', $data, 'id = ?', [$id]);
        } else {
            $id = Db::insert('rules', $data + ['code' => 'r' . bin2hex(random_bytes(4))]);
        }
        Audit::log('rule.save', 'rule', (string) $id, $data);
        Response::flash('success', 'Regra salva.');
        Response::redirect('/regras');
    }

    public static function deleteRule(string $id): never
    {
        Auth::require(true);
        Db::run("UPDATE messages SET status = 'cancelled', status_reason = 'Regra excluída' WHERE rule_id = ? AND status = 'pending'", [(int) $id]);
        Db::run('DELETE FROM rules WHERE id = ?', [(int) $id]);
        Audit::log('rule.delete', 'rule', $id);
        Response::flash('success', 'Regra excluída.');
        Response::redirect('/regras');
    }

    // ---------------------------------------------------------------- Templates
    public static function templates(): never
    {
        Auth::require();
        View::page('templates/index', [
            'title' => 'Modelos de mensagem',
            'templates' => Db::all('SELECT t.*, (SELECT COUNT(*) FROM rules r WHERE r.template_id = t.id AND r.active = 1) AS rules_count FROM templates t ORDER BY t.name'),
        ]);
    }

    public static function editTemplate(string $id): never
    {
        Auth::require();
        $t = $id === 'novo' ? ['id' => 0, 'code' => '', 'name' => '', 'body' => '', 'active' => 1] : Db::one('SELECT * FROM templates WHERE id = ?', [(int) $id]);
        if (!$t) {
            View::page('errors/404', ['title' => 'Não encontrado'], 404);
        }
        View::page('templates/edit', [
            'title' => $t['id'] ? 'Editar modelo' : 'Novo modelo',
            't' => $t,
            'preview' => $t['body'] !== '' ? TemplateRenderer::render($t['body'], TemplateRenderer::sampleVars()) : '',
            'unknown' => TemplateRenderer::unknownVariables((string) $t['body']),
            'variables' => TemplateRenderer::VARIABLES,
        ]);
    }

    public static function saveTemplate(): never
    {
        Auth::require(true);
        $id = Request::int('id');
        $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''));
        $name = mb_substr(Request::str('name'), 0, 120);
        if ($name === '' || trim($body) === '' || mb_strlen($body) > 4000) {
            Response::flash('error', 'Informe nome e texto (até 4000 caracteres).');
            Response::redirect('/templates/' . ($id ?: 'novo'));
        }
        if ($bad = TemplateRenderer::unknownVariables($body)) {
            Response::flash('error', 'Variáveis inexistentes: ' . implode(', ', $bad));
            Response::redirect('/templates/' . ($id ?: 'novo'));
        }
        $data = ['name' => $name, 'body' => $body, 'active' => isset($_POST['active']) ? 1 : 0];
        if ($id) {
            Db::update('templates', $data, 'id = ?', [$id]);
        } else {
            $id = Db::insert('templates', $data + ['code' => 't' . bin2hex(random_bytes(4))]);
        }
        Audit::log('template.save', 'template', (string) $id, ['name' => $name]);
        Response::flash('success', 'Modelo salvo.');
        Response::redirect('/templates/' . $id);
    }

    // ---------------------------------------------------------------- Integrações
    public static function integrations(): never
    {
        Auth::require();
        $wa = EvolutionClient::fromSettings();
        View::page('integrations/index', [
            'title' => 'Integrações',
            's' => Settings::all(),
            'sgpConfigured' => SgpClient::fromSettings()->configured(),
            'waConfigured' => $wa->configured(),
            'waState' => $wa->configured() ? $wa->connectionState() : null,
            'lastRuns' => Db::all('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 5'),
            'webhookUrls' => $_SESSION['_reveal_webhooks'] ?? null,
            'sgpSample' => $_SESSION['_sgp_sample'] ?? null,
        ]);
    }

    private static function cleanUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return preg_match('#^https?://[a-z0-9.\-]+(:\d+)?(/.*)?$#i', $url) ? $url : '';
    }

    public static function saveSgp(): never
    {
        $u = Auth::require(true);
        $url = self::cleanUrl(Request::str('sgp_url'));
        if (Request::str('sgp_url') !== '' && $url === '') {
            Response::flash('error', 'URL do SGP inválida (ex.: https://suaempresa.sgp.tsmx.com.br).');
            Response::redirect('/integracoes');
        }
        Settings::set('sgp_url', $url, $u['id']);
        Settings::set('sgp_app', mb_substr(Request::str('sgp_app'), 0, 100), $u['id']);
        if (Request::str('sgp_token') !== '') {
            Settings::set('sgp_token', Request::str('sgp_token'), $u['id']);
        }
        foreach (['sgp_customer_path', 'sgp_titles_path'] as $k) {
            $p = '/' . ltrim(Request::str($k), '/');
            if (stripos($p, 'fatura2via') !== false) {
                Response::flash('error', 'O endpoint fatura2via abre protocolo no SGP e não pode ser usado.');
                Response::redirect('/integracoes');
            }
            if (preg_match('#^/[a-z0-9/_\-]+/?$#i', $p)) {
                Settings::set($k, $p, $u['id']);
            }
        }
        Settings::set('sgp_sync_interval_minutes', (string) max(5, min(1440, Request::int('sgp_sync_interval_minutes', 30))), $u['id']);
        Settings::set('sgp_sync_batch', (string) max(10, min(5000, Request::int('sgp_sync_batch', 200))), $u['id']);
        Audit::log('integration.sgp.save', 'settings', 'sgp');
        Response::flash('success', 'Configuração do SGP salva.');
        Response::redirect('/integracoes');
    }

    public static function testSgp(): never
    {
        Auth::require(true);
        $sgp = SgpClient::fromSettings();
        $doc = preg_replace('/\D/', '', Request::str('document'));
        if (!$sgp->configured()) {
            Response::flash('error', 'Preencha URL, token e app do SGP.');
            Response::redirect('/integracoes');
        }
        $r = $sgp->test($doc ?: null);
        unset($_SESSION['_sgp_sample']);
        if ($r['ok']) {
            Settings::set('sgp_last_ok_at', now_str());
            $msg = "Conexão com o SGP OK ({$r['ms']} ms).";
            if ($doc !== '') {
                $p = SgpParser::customer($r['json'] ?? []);
                $titles = $sgp->titulos($doc);
                $parsedTitles = $titles['ok'] ? SgpParser::titles($titles['json'] ?? []) : [];
                $_SESSION['_sgp_sample'] = [
                    'customer' => ['nome' => $p['name'], 'telefones' => array_map('mask_phone', $p['phones']), 'contratos' => $p['contracts']],
                    'titles_ok' => $titles['ok'], 'titles_error' => $titles['error'],
                    'titles' => array_map(static fn ($t) => ['id' => $t['sgp_id'], 'vencimento' => $t['due_date'], 'valor' => $t['amount'], 'status' => $t['status'] . ' (' . $t['status_raw'] . ')', 'pix' => $t['pix_code'] ? 'sim' : 'não', 'linha' => $t['barcode'] ? 'sim' : 'não'], array_slice($parsedTitles, 0, 10)),
                    'keys' => array_slice(array_keys(self::flattenKeys($titles['json'] ?? [])), 0, 60),
                ];
                $msg .= ' Veja abaixo como os dados foram interpretados.';
            }
            Logger::info('sgp', 'Teste de conexão com o SGP OK');
            Response::flash('success', $msg);
        } else {
            Response::flash('error', 'Falha na conexão com o SGP: ' . $r['error']);
        }
        Response::redirect('/integracoes');
    }

    private static function flattenKeys(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = is_int($k) ? $prefix . '[]' : ($prefix === '' ? (string) $k : "$prefix.$k");
            if (is_array($v)) {
                $out += self::flattenKeys(is_int($k) ? $v : $v, $key);
            } else {
                $out[$key] = true;
            }
        }
        return $out;
    }

    public static function syncNow(): never
    {
        Auth::require(true);
        Scheduler::trigger('sgp_sync');
        Scheduler::trigger('plan_due');
        Response::flash('success', 'Sincronização agendada: roda em até 1 minuto (acompanhe em SISTEMA > LOGS > SGP).');
        Response::redirect('/integracoes');
    }

    public static function saveWhatsapp(): never
    {
        $u = Auth::require(true);
        $url = self::cleanUrl(Request::str('evolution_url'));
        if (Request::str('evolution_url') !== '' && $url === '') {
            Response::flash('error', 'URL da Evolution API inválida.');
            Response::redirect('/integracoes');
        }
        Settings::set('evolution_url', $url, $u['id']);
        Settings::set('evolution_instance', mb_substr(Request::str('evolution_instance'), 0, 100), $u['id']);
        Settings::set('evolution_version', Request::str('evolution_version') === 'v1' ? 'v1' : 'v2', $u['id']);
        if (Request::str('evolution_apikey') !== '') {
            Settings::set('evolution_apikey', Request::str('evolution_apikey'), $u['id']);
        }
        Audit::log('integration.whatsapp.save', 'settings', 'whatsapp');
        Response::flash('success', 'Configuração do WhatsApp salva.');
        Response::redirect('/integracoes');
    }

    public static function testWhatsapp(): never
    {
        Auth::require(true);
        $wa = EvolutionClient::fromSettings();
        $state = $wa->configured() ? $wa->connectionState() : null;
        if ($state !== null) {
            Settings::set('whatsapp_state', $state);
            Settings::set('whatsapp_state_at', now_str());
        }
        Response::flash($state === 'open' ? 'success' : 'error', match (true) {
            !$wa->configured() => 'Preencha URL, instância e API key.',
            $state === null => 'A Evolution API não respondeu. Verifique URL, API key e instância.',
            $state === 'open' => 'WhatsApp conectado.',
            default => "Instância encontrada, mas o estado é \"$state\". Conecte o WhatsApp (QR Code) na Evolution API.",
        });
        Response::redirect('/integracoes');
    }

    public static function setWebhook(): never
    {
        Auth::require(true);
        $wa = EvolutionClient::fromSettings();
        $r = $wa->setWebhook(WebhookService::urlFor('whatsapp'), WebhookService::tokenFor('whatsapp'));
        if ($r['ok']) {
            ReleaseChecklist::markPassed('webhook', 'Webhook configurado na Evolution API em ' . date('d/m/Y H:i'), Auth::id());
            Audit::log('integration.whatsapp.webhook', 'settings', 'whatsapp');
        }
        Response::flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Webhook configurado na Evolution API.' : 'Falha ao configurar webhook: ' . $r['error']);
        Response::redirect('/integracoes');
    }

    /** Tela "Conectar WhatsApp": QR Code (ou código de pareamento) atualizado automaticamente. */
    public static function connectWhatsapp(): never
    {
        Auth::require(true);
        $wa = EvolutionClient::fromSettings();
        if (!$wa->configured()) {
            Response::flash('error', 'Configure a Evolution API primeiro.');
            Response::redirect('/integracoes');
        }
        $state = $wa->connectionState();
        $conn = null;
        $number = null;
        if ($state !== 'open' && $state !== null) {
            $rawNumber = Request::str('numero');
            $number = $rawNumber !== '' ? Phone::normalize($rawNumber) : null;
            if ($rawNumber !== '' && !$number) {
                Response::flash('error', 'Número inválido. Use DDD + número, ex.: (16) 99999-9999.');
                Response::redirect('/integracoes/whatsapp/conectar');
            }
            $conn = $wa->connect($number);
        }
        if ($state === 'open') {
            Settings::set('whatsapp_state', 'open');
            Settings::set('whatsapp_state_at', now_str());
            if (!Settings::get('whatsapp_owner_number')) {
                $owner = $wa->ownerNumber();
                if ($owner) {
                    Settings::set('whatsapp_owner_number', $owner);
                }
            }
        }
        View::page('integrations/connect', [
            'title' => 'Conectar WhatsApp',
            'state' => $state,
            'conn' => $conn,
            'number' => $number,
            'owner' => Settings::get('whatsapp_owner_number'),
        ]);
    }

    public static function logoutWhatsapp(): never
    {
        Auth::require(true);
        if (!Auth::confirmPassword((string) ($_POST['password'] ?? ''))) {
            Response::flash('error', 'Senha incorreta.');
            Response::redirect('/integracoes/whatsapp/conectar');
        }
        $r = EvolutionClient::fromSettings()->logout();
        Settings::set('whatsapp_owner_number', '');
        Audit::log('integration.whatsapp.logout', 'settings', 'whatsapp');
        Logger::warning('whatsapp', 'Número do WhatsApp desconectado pelo painel');
        Response::flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'WhatsApp desconectado. Escaneie o QR Code para conectar outro número.' : 'Falha ao desconectar: ' . $r['error']);
        Response::redirect('/integracoes/whatsapp/conectar');
    }

    /** Mostra as URLs completas (com token) somente após confirmar a senha. */
    public static function revealWebhooks(): never
    {
        Auth::require(true);
        if (!Auth::confirmPassword((string) ($_POST['password'] ?? ''))) {
            Response::flash('error', 'Senha incorreta.');
            Response::redirect('/integracoes');
        }
        $_SESSION['_reveal_webhooks'] = ['sgp' => WebhookService::urlFor('sgp'), 'whatsapp' => WebhookService::urlFor('whatsapp'), 'until' => time() + 300];
        Audit::log('integration.webhooks.reveal', 'settings', 'webhooks');
        Response::redirect('/integracoes#webhooks');
    }

    // ---------------------------------------------------------------- Homologação / Produção
    public static function homologation(): never
    {
        Auth::require();
        $items = ReleaseChecklist::items();
        View::page('homologation/index', [
            'title' => 'Homologação e liberação',
            's' => Settings::all(),
            'items' => $items,
            'allOk' => ReleaseChecklist::allPassed($items),
            'tests' => ReleaseChecklist::TESTS,
            'sentHomolog' => (int) Db::value('SELECT COUNT(*) FROM messages WHERE homologation = 1 AND is_test = 0 AND sent_at >= CURDATE()'),
        ]);
    }

    public static function saveHomologation(): never
    {
        $u = Auth::require(true);
        $raw = Request::str('test_number');
        $n = $raw === '' ? '' : Phone::normalize($raw);
        if ($n === null) {
            Response::flash('error', 'Número de teste inválido. Use DDD + número, ex.: (11) 98765-4321.');
            Response::redirect('/homologacao');
        }
        Settings::set('test_number', $n, $u['id']);
        Settings::set('homologation_daily_limit', (string) max(0, min(1000, Request::int('homologation_daily_limit', 30))), $u['id']);
        Audit::log('homologation.save', 'settings', 'test_number', ['test_number' => mask_phone($n)]);
        Response::flash('success', 'Configurações de homologação salvas.');
        Response::redirect('/homologacao');
    }

    public static function sendTest(): never
    {
        Auth::require(true);
        $code = Request::str('code');
        if (!isset(ReleaseChecklist::TESTS[$code])) {
            Response::flash('error', 'Teste inválido.');
            Response::redirect('/homologacao');
        }
        [$ok, $msg] = Planner::createTest($code, ReleaseChecklist::TESTS[$code][1]);
        Audit::log('homologation.test', 'release_check', $code);
        Response::flash($ok ? 'success' : 'error', $msg);
        Response::redirect('/homologacao');
    }

    public static function validateNumber(): never
    {
        $u = Auth::require(true);
        [$ok, $msg] = ReleaseChecklist::validateWhatsappNumber((int) $u['id']);
        Response::flash($ok ? 'success' : 'error', $ok ? 'Número validado: ' . $msg : $msg);
        Response::redirect(Request::str('back') === 'integracoes' ? '/integracoes' : '/homologacao');
    }

    public static function release(): never
    {
        $u = Auth::require(true);
        if (Request::str('confirm') !== 'LIBERAR' || !Auth::confirmPassword((string) ($_POST['password'] ?? ''))) {
            Response::flash('error', 'Confirmação inválida: digite LIBERAR e sua senha.');
            Response::redirect('/homologacao');
        }
        if (!ReleaseChecklist::allPassed(ReleaseChecklist::items())) {
            Response::flash('error', 'Ainda há itens pendentes no checklist.');
            Response::redirect('/homologacao');
        }
        ReleaseChecklist::release((int) $u['id']);
        Response::flash('success', 'Sistema liberado para PRODUÇÃO. As mensagens agora vão para os clientes reais.');
        Response::redirect('/homologacao');
    }

    public static function backToHomologation(): never
    {
        $u = Auth::require(true);
        ReleaseChecklist::backToHomologation((int) $u['id']);
        Response::flash('success', 'Sistema voltou ao modo homologação. Nenhuma mensagem será enviada a clientes reais.');
        Response::redirect('/homologacao');
    }
}
