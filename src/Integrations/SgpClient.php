<?php
declare(strict_types=1);

namespace App\Integrations;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Cliente da API URA do SGP (TSMX). Autenticação por token + app no corpo (form-urlencoded).
 * Os caminhos dos endpoints são configuráveis em Integrações > SGP.
 *
 * SEGURANÇA: o endpoint /api/ura/fatura2via/ abre protocolo de atendimento no SGP
 * e NUNCA deve ser chamado automaticamente — ele é bloqueado aqui.
 */
final class SgpClient
{
    private const BLOCKED_PATHS = ['fatura2via', 'chamado', 'liberacao', 'desbloqueio'];

    public function __construct(
        private string $baseUrl,
        private string $token,
        private string $app,
        private string $customerPath = '/api/ura/consultacliente/',
        private string $titlesPath = '/api/ura/titulos/',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromSettings(): self
    {
        return new self(
            (string) Settings::get('sgp_url', ''),
            (string) Settings::get('sgp_token', ''),
            (string) Settings::get('sgp_app', ''),
            (string) Settings::get('sgp_customer_path', '/api/ura/consultacliente/'),
            (string) Settings::get('sgp_titles_path', '/api/ura/titulos/'),
        );
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '' && $this->app !== '';
    }

    /** @return array{ok:bool,status:int,json:?array,error:?string,ms:int} */
    public function post(string $path, array $params): array
    {
        foreach (self::BLOCKED_PATHS as $blocked) {
            if (stripos($path, $blocked) !== false) {
                throw new \LogicException("Endpoint SGP bloqueado por segurança: $path");
            }
        }
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'SGP não configurado', 'ms' => 0];
        }
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $r = HttpClient::request('POST', $url, [], ['token' => $this->token, 'app' => $this->app] + $params, 30, true);
        $error = $r['error'];
        if (!$error && ($r['status'] < 200 || $r['status'] >= 300)) {
            $error = 'HTTP ' . $r['status'];
        }
        if (!$error && $r['json'] === null) {
            $error = 'Resposta não é JSON';
        }
        if (!$error && is_array($r['json']) && self::looksLikeAuthError($r['json'])) {
            $error = 'Token/app recusados pelo SGP';
        }
        if ($error) {
            Logger::warning('sgp', "Falha na chamada SGP $path: $error", ['status' => $r['status'], 'ms' => $r['ms']]);
        }
        return ['ok' => $error === null, 'status' => $r['status'], 'json' => $r['json'], 'error' => $error, 'ms' => $r['ms']];
    }

    public function consultaCliente(string $document): array
    {
        return $this->post($this->customerPath, ['cpfcnpj' => preg_replace('/\D/', '', $document)]);
    }

    public function titulos(string $document, ?string $contract = null): array
    {
        $p = ['cpfcnpj' => preg_replace('/\D/', '', $document)];
        if ($contract) {
            $p['contrato'] = $contract;
        }
        return $this->post($this->titlesPath, $p);
    }

    /** Testa as credenciais com uma consulta inofensiva (somente leitura). */
    public function test(?string $document = null): array
    {
        $r = $this->consultaCliente($document ?: '00000000000');
        if (!$r['ok'] && $r['status'] === 200 && $r['error'] === null) {
            $r['ok'] = true;
        }
        return $r;
    }

    private static function looksLikeAuthError(array $json): bool
    {
        $flat = strtolower((string) json_encode(array_intersect_key($json, array_flip(['msg', 'message', 'erro', 'error', 'detail', 'detalhe']))));
        return $flat !== '[]' && (str_contains($flat, 'token') || str_contains($flat, 'autentica') || str_contains($flat, 'unauthorized') || str_contains($flat, 'permiss'));
    }
}
