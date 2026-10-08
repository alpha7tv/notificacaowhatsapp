<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Cliente mínimo da Evolution API (v2) para a instância do Dominius Play.
 * Usa a chave da PRÓPRIA instância (EVOLUTION_API_KEY), nunca a chave geral do servidor.
 */
final class EvolutionClient
{
    public static function configured(): bool
    {
        return self::instance() !== '' && trim((string) Env::get('EVOLUTION_API_KEY', '')) !== '';
    }

    public static function instance(): string
    {
        return trim((string) Env::get('EVOLUTION_INSTANCE', ''));
    }

    /** @return array{code:int,json:?array,error:?string} */
    public static function request(string $method, string $path, ?array $body = null, int $timeout = 15): array
    {
        $base = rtrim((string) Env::get('EVOLUTION_URL', 'http://127.0.0.1:8080'), '/');
        $ch = curl_init($base . $path);
        $headers = ['apikey: ' . (string) Env::get('EVOLUTION_API_KEY', '')];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['code' => 0, 'json' => null, 'error' => 'Sem conexão com a Evolution: ' . $err];
        }
        $json = json_decode((string) $raw, true);
        return ['code' => $code, 'json' => is_array($json) ? $json : null, 'error' => null];
    }

    /** open | connecting | close | not_configured | auth | unknown | error */
    public static function state(): string
    {
        if (!self::configured()) {
            return 'not_configured';
        }
        $r = self::request('GET', '/instance/connectionState/' . rawurlencode(self::instance()));
        if ($r['error'] !== null || $r['code'] === 0) {
            return 'error';
        }
        if ($r['code'] === 401 || $r['code'] === 403) {
            return 'auth';
        }
        if ($r['code'] === 404) {
            return 'unknown';
        }
        $state = $r['json']['instance']['state'] ?? $r['json']['state'] ?? null;
        return is_string($state) && $state !== '' ? $state : 'unknown';
    }

    /** Gera/recupera o QR code atual: PNG em binário, ou null se ainda não estiver pronto. */
    public static function qrPng(): ?string
    {
        if (!self::configured()) {
            return null;
        }
        for ($i = 0; $i < 4; $i++) {
            $r = self::request('GET', '/instance/connect/' . rawurlencode(self::instance()), null, 20);
            $b64 = (string) ($r['json']['base64'] ?? '');
            if ($b64 !== '') {
                $b64 = preg_replace('#^data:image/[a-z]+;base64,#i', '', $b64) ?? $b64;
                $bin = base64_decode($b64, true);
                if ($bin !== false && str_starts_with($bin, "\x89PNG")) {
                    return $bin;
                }
            }
            sleep(2);
        }
        return null;
    }

    public static function logout(): bool
    {
        if (!self::configured()) {
            return false;
        }
        $r = self::request('DELETE', '/instance/logout/' . rawurlencode(self::instance()));
        return $r['code'] >= 200 && $r['code'] < 300;
    }
}
