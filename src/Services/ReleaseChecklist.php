<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Settings;
use App\Integrations\EvolutionClient;

/** Checklist obrigatório antes de LIBERAR PRODUÇÃO. */
final class ReleaseChecklist
{
    public const TESTS = [
        'test_due' => ['Teste de vencimento realizado', 'before_due'],
        'test_payment' => ['Teste de pagamento realizado', 'payment_confirmed'],
        'test_suspension' => ['Teste de suspensão realizado', 'suspended'],
        'test_cancellation' => ['Teste de cancelamento realizado', 'cancelled'],
    ];

    public static function markPassed(string $code, string $details, ?int $userId = null): void
    {
        Db::run('INSERT INTO release_checks (code, passed, details, checked_at, checked_by) VALUES (?,1,?,NOW(),?)
            ON DUPLICATE KEY UPDATE passed = 1, details = VALUES(details), checked_at = NOW(), checked_by = VALUES(checked_by)',
            [$code, mb_substr($details, 0, 500), $userId]);
    }

    private static function manual(string $code): ?array
    {
        return Db::one('SELECT * FROM release_checks WHERE code = ? AND passed = 1', [$code]);
    }

    /** @return array<int, array{code:string,label:string,ok:bool,detail:string}> */
    public static function items(bool $live = true): array
    {
        $items = [];
        $integ = HealthService::integrations($live);
        $items[] = ['code' => 'sgp', 'label' => 'SGP conectado', 'ok' => $integ['sgp'] === 'ok', 'detail' => $integ['sgp_detail'] ?: 'Configure em Integrações'];
        $items[] = ['code' => 'whatsapp', 'label' => 'WhatsApp conectado', 'ok' => $integ['whatsapp'] === 'ok', 'detail' => $integ['whatsapp_detail'] ?: 'Configure em Integrações'];

        $v = self::manual('whatsapp_number');
        $items[] = ['code' => 'whatsapp_number', 'label' => 'Número WhatsApp validado', 'ok' => (bool) $v, 'detail' => $v['details'] ?? 'Use "Validar número" em Integrações > WhatsApp'];

        [$tplOk, $tplDetail] = self::templatesOk();
        $items[] = ['code' => 'templates', 'label' => 'Templates configurados', 'ok' => $tplOk, 'detail' => $tplDetail];

        $tz = date_default_timezone_get();
        $dbOffset = (string) Db::value("SELECT TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), '%H:%i')");
        $phpOffset = (new \DateTimeImmutable())->format('H:i');
        $tzOk = $tz === 'America/Sao_Paulo' && ltrim($dbOffset, '-') === ltrim((new \DateTimeImmutable())->format('P'), '+-');
        $items[] = ['code' => 'timezone', 'label' => 'Timezone correto', 'ok' => $tzOk, 'detail' => "$tz, banco UTC$dbOffset, hora atual $phpOffset"];

        $s = Heartbeat::age('scheduler');
        $items[] = ['code' => 'scheduler', 'label' => 'Scheduler funcionando', 'ok' => $s !== null && $s < 180, 'detail' => $s === null ? 'Nunca executou' : "Último sinal há {$s}s"];
        $w = Heartbeat::age('worker');
        $items[] = ['code' => 'worker', 'label' => 'Worker funcionando', 'ok' => $w !== null && $w < 120, 'detail' => $w === null ? 'Nunca executou' : "Último sinal há {$w}s"];

        $wh = self::manual('webhook');
        $items[] = ['code' => 'webhook', 'label' => 'Webhook configurado', 'ok' => (bool) $wh, 'detail' => $wh['details'] ?? 'Use "Configurar webhook" em Integrações > WhatsApp'];

        $health = ServerStatus::statusFile('health');
        $sslOk = ($health['checks']['ssl_painel']['status'] ?? '') === 'ok' && ($health['checks']['ssl_api']['status'] ?? '') === 'ok';
        $items[] = ['code' => 'ssl', 'label' => 'SSL válido', 'ok' => $sslOk, 'detail' => $health ? ('Painel: ' . ($health['checks']['ssl_painel']['detail'] ?? '?') . ' / API: ' . ($health['checks']['ssl_api']['detail'] ?? '?')) : 'Aguardando health check do servidor'];

        $backup = ServerStatus::statusFile('backup');
        $bOk = $backup && ($backup['status'] ?? '') === 'ok' && strtotime((string) ($backup['finished_at'] ?? '')) > strtotime('-26 hours');
        $items[] = ['code' => 'backup', 'label' => 'Backup configurado', 'ok' => (bool) $bOk, 'detail' => $backup ? ('Último backup: ' . fmt_datetime($backup['finished_at'] ?? null) . ' (' . ($backup['status'] ?? '?') . ')') : 'Nenhum backup registrado ainda'];

        foreach (self::TESTS as $code => [$label]) {
            $t = self::manual($code);
            $items[] = ['code' => $code, 'label' => $label, 'ok' => (bool) $t, 'detail' => $t['details'] ?? 'Clique em "Enviar teste"'];
        }
        return $items;
    }

    public static function templatesOk(): array
    {
        $rules = Db::all('SELECT r.name, t.body, t.active FROM rules r JOIN templates t ON t.id = r.template_id WHERE r.active = 1');
        if (!$rules) {
            return [false, 'Nenhuma regra ativa'];
        }
        foreach ($rules as $r) {
            if ((int) $r['active'] !== 1 || trim((string) $r['body']) === '') {
                return [false, "Regra \"{$r['name']}\" usa modelo inativo ou vazio"];
            }
            if ($bad = TemplateRenderer::unknownVariables((string) $r['body'])) {
                return [false, "Regra \"{$r['name']}\" usa variável inexistente: " . implode(', ', $bad)];
            }
        }
        return [true, count($rules) . ' regra(s) ativa(s) com modelos válidos'];
    }

    public static function allPassed(array $items): bool
    {
        foreach ($items as $i) {
            if (!$i['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param bool $keepPending true: as mensagens pendentes criadas na homologação seguem para os
     *   clientes reais (espaçadas pelo intervalo anti-bloqueio e revalidadas no envio);
     *   false: são canceladas.
     */
    public static function release(int $userId, bool $keepPending = false): void
    {
        $cancelled = 0;
        $kept = (int) Db::value("SELECT COUNT(*) FROM messages WHERE status = 'pending' AND is_test = 0");
        if (!$keepPending) {
            $cancelled = Db::run("UPDATE messages SET status = 'cancelled', status_reason = 'Criada durante a homologação'
                WHERE status = 'pending' AND is_test = 0")->rowCount();
            $kept = 0;
        }
        Settings::set('mode', 'production', $userId);
        Settings::set('production_released_at', now_str(), $userId);
        Audit::log('production.release', 'settings', 'mode', ['cancelled_pending' => $cancelled, 'kept_pending' => $kept]);
        Logger::warning('app', "SISTEMA LIBERADO PARA PRODUÇÃO — envios reais habilitados ($kept pendente(s) mantida(s), $cancelled cancelada(s))");
    }

    public static function backToHomologation(int $userId): void
    {
        Settings::set('mode', 'homologation', $userId);
        Audit::log('production.revert', 'settings', 'mode');
        Logger::warning('app', 'Sistema retornou ao MODO HOMOLOGAÇÃO');
    }

    public static function validateWhatsappNumber(int $userId): array
    {
        $wa = EvolutionClient::fromSettings();
        if (!$wa->configured()) {
            return [false, 'Configure o WhatsApp primeiro.'];
        }
        if ($wa->connectionState() !== 'open') {
            return [false, 'A instância não está conectada. Escaneie o QR Code na Evolution API.'];
        }
        $owner = $wa->ownerNumber();
        $test = Phone::normalize((string) Settings::get('test_number', ''));
        if (!$test) {
            return [false, 'Configure o número de teste em Homologação.'];
        }
        $exists = $wa->checkNumbers([$test])[$test] ?? null;
        if ($exists === false) {
            return [false, 'O número de teste informado não possui WhatsApp.'];
        }
        if ($owner) {
            Settings::set('whatsapp_owner_number', $owner, $userId);
        }
        $detail = 'Remetente ' . ($owner ? mask_phone($owner) : '(não informado pela API)') . ', teste ' . mask_phone($test) . ' validado em ' . date('d/m/Y H:i');
        self::markPassed('whatsapp_number', $detail, $userId);
        return [true, $detail];
    }

    public static function appEnvOk(): bool
    {
        return App::isProduction();
    }
}
