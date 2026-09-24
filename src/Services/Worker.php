<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Core\Settings;
use App\Integrations\EvolutionClient;

/**
 * Worker da fila de WhatsApp (serviço systemd "fiberlink-worker", Restart=always).
 * Encerra sozinho após $maxSeconds/$maxJobs para liberar memória e carregar código novo;
 * o systemd o reinicia automaticamente.
 */
final class Worker
{
    private const BACKOFF = [60, 300, 900, 3600, 10800];

    private bool $stop = false;
    private string $id;
    private int $lastBeat = 0;

    public function __construct(private ?EvolutionClient $client = null)
    {
        $this->id = (gethostname() ?: 'host') . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
    }

    public function run(int $maxSeconds = 3600, int $maxJobs = 1000, bool $once = false): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        Logger::info('worker', "Worker iniciado ({$this->id})");
        $started = time();
        $jobs = 0;
        $warnedNotConfigured = false;

        while (!$this->stop && time() - $started < $maxSeconds && $jobs < $maxJobs) {
            try {
                $this->beat('ativo');
                Settings::flush();
                $this->client = EvolutionClient::fromSettings();

                if (!$this->client->configured()) {
                    if (!$warnedNotConfigured) {
                        Logger::warning('worker', 'WhatsApp não configurado: a fila fica aguardando (configure em Integrações).');
                        $warnedNotConfigured = true;
                    }
                    if ($once) {
                        return;
                    }
                    $this->sleep(15);
                    continue;
                }
                $warnedNotConfigured = false;

                $maxHour = Settings::int('send_max_per_hour', 200);
                $lastHour = (int) Db::value("SELECT COUNT(*) FROM messages WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                if ($maxHour > 0 && $lastHour >= $maxHour) {
                    $this->beat('limite por hora atingido');
                    if ($once) {
                        return;
                    }
                    $this->sleep(30);
                    continue;
                }

                $msg = $this->claim();
                if (!$msg) {
                    if ($once) {
                        return;
                    }
                    $this->sleep(3);
                    continue;
                }
                $jobs++;
                $attempted = $this->process($msg);
                if ($once) {
                    continue;
                }
                if ($attempted) {
                    $interval = max(1, Settings::int('send_interval_seconds', 10));
                    $this->sleep($interval + random_int(0, (int) ceil($interval * 0.4)));
                }
            } catch (\PDOException $e) {
                Logger::error('worker', 'Erro de banco no worker: ' . $e->getMessage());
                Db::reset();
                if ($once) {
                    throw $e;
                }
                $this->sleep(10);
            } catch (\Throwable $e) {
                Logger::error('worker', 'Erro no worker: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
                if ($once) {
                    throw $e;
                }
                $this->sleep(5);
            }
        }
        Logger::info('worker', "Worker encerrando ({$jobs} mensagem(ns) processada(s)); o systemd reinicia automaticamente.");
    }

    private function beat(string $info): void
    {
        if (time() - $this->lastBeat >= 15) {
            Heartbeat::beat('worker', $info);
            $this->lastBeat = time();
        }
    }

    private function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->stop; $i++) {
            sleep(1);
        }
    }

    /** Reserva atomicamente uma mensagem (seguro com vários workers). */
    public function claim(): ?array
    {
        $n = Db::run("UPDATE messages SET status = 'processing', locked_by = ?, locked_at = NOW(), attempts = attempts + 1
            WHERE status = 'pending' AND available_at <= NOW() ORDER BY available_at, id LIMIT 1", [$this->id])->rowCount();
        if ($n === 0) {
            return null;
        }
        return Db::one("SELECT m.*, r.send_start, r.send_end FROM messages m LEFT JOIN rules r ON r.id = m.rule_id
            WHERE m.locked_by = ? AND m.status = 'processing' ORDER BY m.locked_at DESC LIMIT 1", [$this->id]);
    }

    /** @return bool true se houve tentativa real de envio (aplica o intervalo anti-bloqueio) */
    public function process(array $m): bool
    {
        $id = (int) $m['id'];
        $isTest = (int) $m['is_test'] === 1;

        // 1) Janela de horário (mensagens de teste ignoram a janela)
        if (!$isTest && $m['send_start'] && !Planner::inWindow((string) $m['send_start'], (string) $m['send_end'])) {
            $next = Planner::nextSendTime((string) $m['send_start'], (string) $m['send_end']);
            $this->release($id, $next->format('Y-m-d H:i:s'), true);
            return false;
        }

        // 2) Revalidação: a situação mudou desde que a mensagem foi criada?
        if (!$isTest && ($reason = $this->obsoleteReason($m))) {
            $this->finish($id, 'cancelled', ['status_reason' => $reason]);
            return false;
        }

        // 3) Destino (modo homologação redireciona TUDO para o número de teste)
        $homolog = Settings::isHomologation();
        $testNumber = Phone::normalize((string) Settings::get('test_number', ''));
        $body = (string) $m['body'];
        if ($isTest) {
            if (!$testNumber) {
                $this->finish($id, 'failed', ['last_error' => 'Número de teste não configurado']);
                return false;
            }
            $to = $testNumber;
        } elseif ($homolog) {
            if (!$testNumber) {
                $this->finish($id, 'skipped', ['status_reason' => 'Modo homologação sem número de teste: nada é enviado a clientes']);
                return false;
            }
            $limit = Settings::int('homologation_daily_limit', 30);
            $today = (int) Db::value("SELECT COUNT(*) FROM messages WHERE homologation = 1 AND is_test = 0 AND sent_at >= CURDATE()");
            if ($limit > 0 && $today >= $limit) {
                $this->finish($id, 'skipped', ['status_reason' => "Limite diário de homologação ($limit) atingido"]);
                return false;
            }
            $to = $testNumber;
            $body = "🧪 *HOMOLOGAÇÃO* — destino real: " . mask_phone((string) $m['destination']) . "\n\n" . $body;
        } else {
            $to = Phone::normalize((string) $m['destination']);
            if (!$to) {
                $this->finish($id, 'failed', ['last_error' => 'Telefone de destino inválido']);
                return false;
            }
            if ($m['customer_id'] && !$this->hasWhatsapp((int) $m['customer_id'], $to)) {
                $this->finish($id, 'failed', ['last_error' => 'Número não possui WhatsApp']);
                return false;
            }
        }

        // 4) Envio
        $r = $this->client->sendText($to, $body);
        if ($r['ok']) {
            $this->finish($id, 'sent', [
                'sent_to' => $to, 'sent_at' => now_str(), 'provider_message_id' => $r['id'],
                'homologation' => ($homolog || $isTest) ? 1 : 0, 'last_error' => null, 'status_reason' => null,
            ]);
            Logger::info('whatsapp', "Mensagem #$id enviada" . ($homolog && !$isTest ? ' (homologação → número de teste)' : ''), ['to' => mask_phone($to), 'event' => $m['event']]);
            if ($isTest && $m['test_code']) {
                ReleaseChecklist::markPassed((string) $m['test_code'], 'Mensagem de teste #' . $id . ' enviada em ' . date('d/m/Y H:i'));
            }
            return true;
        }

        $attempts = (int) $m['attempts'];
        if ($r['retryable'] && $attempts < (int) $m['max_attempts']) {
            $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
            Db::run("UPDATE messages SET status = 'pending', locked_by = NULL, locked_at = NULL, last_error = ?,
                available_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?", [mb_substr((string) $r['error'], 0, 500), $delay, $id]);
            Logger::warning('worker', "Mensagem #$id falhou (tentativa $attempts), nova tentativa em {$delay}s");
        } else {
            $this->finish($id, 'failed', ['last_error' => mb_substr((string) $r['error'], 0, 500)]);
            Logger::error('worker', "Mensagem #$id falhou definitivamente: " . $r['error']);
        }
        return true;
    }

    private function obsoleteReason(array $m): ?string
    {
        if ($m['customer_id']) {
            $optOut = Db::value('SELECT opt_out FROM customers WHERE id = ?', [$m['customer_id']]);
            if ((int) $optOut === 1) {
                return 'Cliente optou por não receber mensagens';
            }
        }
        if (in_array($m['event'], Planner::DUE_EVENTS, true)) {
            if ($m['invoice_id']) {
                $st = Db::value('SELECT status FROM invoices WHERE id = ?', [$m['invoice_id']]);
                if ($st !== 'open') {
                    return $st === 'paid' ? 'Fatura paga antes do envio' : 'Fatura não está mais em aberto';
                }
            }
            if ($m['contract_id'] && Db::value('SELECT status FROM contracts WHERE id = ?', [$m['contract_id']]) === 'cancelled') {
                return 'Contrato cancelado';
            }
            // Mensagens de vencimento só valem no dia planejado (+1 dia de tolerância)
            if (strtotime((string) $m['created_at']) < strtotime('-2 days')) {
                return 'Mensagem de vencimento expirada (criada há mais de 2 dias)';
            }
        }
        if (in_array($m['event'], ['suspended', 'cancelled', 'reactivated'], true) && $m['contract_id']) {
            $st = Db::value('SELECT status FROM contracts WHERE id = ?', [$m['contract_id']]);
            $expected = ['suspended' => 'suspended', 'cancelled' => 'cancelled', 'reactivated' => 'active'][$m['event']];
            if ($st !== $expected) {
                return 'Situação do contrato mudou antes do envio';
            }
        }
        return null;
    }

    private function hasWhatsapp(int $customerId, string $number): bool
    {
        $c = Db::one('SELECT wa_exists, wa_checked_at FROM customers WHERE id = ?', [$customerId]);
        if ($c && $c['wa_checked_at'] && strtotime((string) $c['wa_checked_at']) > strtotime('-30 days')) {
            return (int) $c['wa_exists'] === 1;
        }
        $res = $this->client->checkNumbers([$number]);
        $exists = $res[$number] ?? null;
        if ($exists === null) {
            return true; // não foi possível verificar: tenta enviar
        }
        Db::update('customers', ['wa_exists' => $exists ? 1 : 0, 'wa_checked_at' => now_str()], 'id = ?', [$customerId]);
        return $exists;
    }

    private function release(int $id, string $availableAt, bool $undoAttempt): void
    {
        Db::run("UPDATE messages SET status = 'pending', locked_by = NULL, locked_at = NULL, available_at = ?"
            . ($undoAttempt ? ', attempts = GREATEST(attempts - 1, 0)' : '') . ' WHERE id = ?', [$availableAt, $id]);
    }

    private function finish(int $id, string $status, array $extra = []): void
    {
        Db::update('messages', ['status' => $status, 'locked_by' => null, 'locked_at' => null] + $extra, 'id = ?', [$id]);
        if (in_array($status, ['cancelled', 'skipped'], true)) {
            Logger::info('worker', "Mensagem #$id: $status — " . ($extra['status_reason'] ?? ''));
        }
    }
}
