<?php
declare(strict_types=1);

namespace App\Integrations;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Cliente da Evolution API (WhatsApp não-oficial, conexão via QR Code).
 * Suporta as versões v1 e v2 (formato do corpo de sendText muda entre elas).
 */
final class EvolutionClient
{
    public function __construct(
        private string $baseUrl,
        private string $instance,
        private string $apiKey,
        private string $version = 'v2',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromSettings(): self
    {
        return new self(
            (string) Settings::get('evolution_url', ''),
            (string) Settings::get('evolution_instance', ''),
            (string) Settings::get('evolution_apikey', ''),
            (string) Settings::get('evolution_version', 'v2'),
        );
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->instance !== '' && $this->apiKey !== '';
    }

    private function call(string $method, string $path, ?array $body = null, int $timeout = 25): array
    {
        if (!$this->configured()) {
            return ['status' => 0, 'json' => null, 'error' => 'WhatsApp não configurado', 'ms' => 0, 'body' => ''];
        }
        $r = HttpClient::request($method, $this->baseUrl . $path, ['apikey' => $this->apiKey], $body, $timeout);
        if (!$r['error'] && ($r['status'] < 200 || $r['status'] >= 300)) {
            $msg = $r['json']['response']['message'] ?? $r['json']['message'] ?? $r['json']['error'] ?? null;
            $r['error'] = 'HTTP ' . $r['status'] . ($msg ? ': ' . mb_substr(is_array($msg) ? (string) json_encode($msg, JSON_UNESCAPED_UNICODE) : (string) $msg, 0, 200) : '');
        }
        return $r;
    }

    private function inst(): string
    {
        return rawurlencode($this->instance);
    }

    /** @return string|null open | close | connecting | null (erro) */
    public function connectionState(): ?string
    {
        $r = $this->call('GET', '/instance/connectionState/' . $this->inst(), null, 10);
        if ($r['error']) {
            return null;
        }
        $state = $r['json']['instance']['state'] ?? $r['json']['state'] ?? null;
        return is_string($state) ? $state : null;
    }

    /** Número conectado na instância (dono do WhatsApp). */
    public function ownerNumber(): ?string
    {
        $r = $this->call('GET', '/instance/fetchInstances?instanceName=' . $this->inst(), null, 10);
        if ($r['error'] || !is_array($r['json'])) {
            return null;
        }
        $flat = (string) json_encode($r['json']);
        if (preg_match('/"(?:ownerJid|owner|wuid)"\s*:\s*"(\d{10,15})@/', $flat, $m)) {
            return $m[1];
        }
        if (preg_match('/"number"\s*:\s*"(\d{10,15})"/', $flat, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return array<string,bool|null> número => existe no WhatsApp (null = não foi possível verificar) */
    public function checkNumbers(array $numbers): array
    {
        $out = array_fill_keys($numbers, null);
        $r = $this->call('POST', '/chat/whatsappNumbers/' . $this->inst(), ['numbers' => array_values($numbers)], 20);
        if ($r['error'] || !is_array($r['json'])) {
            return $out;
        }
        foreach ($r['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $num = preg_replace('/\D/', '', (string) ($item['number'] ?? explode('@', (string) ($item['jid'] ?? ''))[0]));
            foreach ($numbers as $n) {
                if ($num !== '' && (str_ends_with($num, substr($n, -8)) || $num === $n)) {
                    $out[$n] = (bool) ($item['exists'] ?? false);
                }
            }
        }
        return $out;
    }

    /** @return array{ok:bool,id:?string,error:?string,retryable:bool} */
    public function sendText(string $number, string $text): array
    {
        $body = $this->version === 'v1'
            ? ['number' => $number, 'options' => ['delay' => 1200, 'presence' => 'composing'], 'textMessage' => ['text' => $text]]
            : ['number' => $number, 'text' => $text, 'delay' => 1200];
        $r = $this->call('POST', '/message/sendText/' . $this->inst(), $body, 40);
        if ($r['error']) {
            $retryable = $r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429;
            Logger::warning('whatsapp', 'Falha no envio: ' . $r['error'], ['to' => mask_phone($number), 'status' => $r['status']]);
            return ['ok' => false, 'id' => null, 'error' => $r['error'], 'retryable' => $retryable];
        }
        $id = $r['json']['key']['id'] ?? $r['json']['id'] ?? $r['json']['message']['key']['id'] ?? null;
        return ['ok' => true, 'id' => is_string($id) ? $id : null, 'error' => null, 'retryable' => false];
    }

    /**
     * Inicia a conexão da instância. Retorna o QR Code (imagem base64) e/ou o código de
     * pareamento (quando $number é informado: o usuário digita o código no WhatsApp).
     * @return array{ok:bool,qr:?string,pairing:?string,state:?string,error:?string}
     */
    public function connect(?string $number = null): array
    {
        $path = '/instance/connect/' . $this->inst() . ($number ? '?number=' . rawurlencode($number) : '');
        $r = $this->call('GET', $path, null, 30);
        if ($r['error']) {
            return ['ok' => false, 'qr' => null, 'pairing' => null, 'state' => null, 'error' => $r['error']];
        }
        $j = $r['json'] ?? [];
        $qr = $j['base64'] ?? $j['qrcode']['base64'] ?? null;
        $pairing = $j['pairingCode'] ?? $j['qrcode']['pairingCode'] ?? null;
        $state = $j['instance']['state'] ?? null;
        if (is_string($qr) && !str_starts_with($qr, 'data:image/')) {
            $qr = 'data:image/png;base64,' . $qr;
        }
        return [
            'ok' => true,
            'qr' => is_string($qr) && preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $qr) ? $qr : null,
            'pairing' => is_string($pairing) && preg_match('/^[A-Z0-9-]{4,12}$/i', $pairing) ? $pairing : null,
            'state' => is_string($state) ? $state : null,
            'error' => null,
        ];
    }

    /** Desconecta o número da instância (será necessário escanear o QR Code novamente). */
    public function logout(): array
    {
        $r = $this->call('DELETE', '/instance/logout/' . $this->inst(), null, 20);
        return ['ok' => $r['error'] === null, 'error' => $r['error']];
    }

    /** Configura o webhook da instância para apontar para a API deste sistema. */
    public function setWebhook(string $url, string $token): array
    {
        $events = ['MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'SEND_MESSAGE'];
        $body = $this->version === 'v1'
            ? ['url' => $url, 'enabled' => true, 'webhook_by_events' => false, 'webhook_base64' => false, 'events' => $events]
            : ['webhook' => ['enabled' => true, 'url' => $url, 'headers' => ['X-Webhook-Token' => $token], 'byEvents' => false, 'base64' => false, 'events' => $events]];
        $r = $this->call('POST', '/webhook/set/' . $this->inst(), $body, 20);
        return ['ok' => $r['error'] === null, 'error' => $r['error']];
    }
}
