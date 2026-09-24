<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Mascara dados sensíveis antes de qualquer registro em log, banco ou tela.
 * Nunca registrar: senhas, tokens completos, Authorization, APP_KEY, DB_PASSWORD, PIX completo.
 */
final class Masker
{
    private const SENSITIVE_KEY = '/(pass(word)?|senha|secret|token|api[_-]?key|authorization|app_key|db_password|cookie|jwt|private|credential|signature|hash)/i';
    private const PIX_KEY = '/(pix|qrcode|copia_?e?_?cola|emv)/i';

    public static function value(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::array($value);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return preg_match(self::SENSITIVE_KEY, $key) && $value !== null ? '***' : $value;
        }
        $str = (string) $value;
        if (preg_match(self::SENSITIVE_KEY, $key)) {
            return self::secret($str);
        }
        if (preg_match(self::PIX_KEY, $key)) {
            return self::pix($str);
        }
        return self::string($str);
    }

    public static function array(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = self::value((string) $k, $v);
        }
        return $out;
    }

    /** Mostra somente os 4 primeiros caracteres de um segredo. */
    public static function secret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return mb_strlen($value) <= 8 ? '***' : mb_substr($value, 0, 4) . '***(' . mb_strlen($value) . ')';
    }

    public static function pix(string $value): string
    {
        return mb_strlen($value) <= 20 ? '***' : mb_substr($value, 0, 12) . '…' . mb_substr($value, -4);
    }

    /** Mascara padrões sensíveis dentro de textos livres. */
    public static function string(string $text): string
    {
        $patterns = [
            // Authorization: Bearer xxx / Basic xxx
            '/(authorization["\']?\s*[:=]\s*["\']?(bearer|basic)?\s*)([A-Za-z0-9._~+\/=-]{6})[A-Za-z0-9._~+\/=-]*/i' => '$1$3***',
            '/\b(bearer\s+)([A-Za-z0-9._~+\/=-]{4})[A-Za-z0-9._~+\/=-]{8,}/i' => '$1$2***',
            // token=..., apikey=..., senha=..., password=... em query strings / JSON
            '/((?:token|apikey|api_key|senha|password|secret|app_key|db_password)["\']?\s*[:=]\s*["\']?)([^"\'&\s,}]{0,4})[^"\'&\s,}]*/i' => '$1$2***',
            // JWT
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/' => 'eyJ***.jwt',
            // PIX copia-e-cola (EMV BR Code)
            '/\b(000201[0-9A-Za-z.@\-\/ ]{8})[0-9A-Za-z.@\-\/*$ ]{20,}(\w{4})\b/' => '$1…$2',
            // base64:APP_KEY
            '/base64:[A-Za-z0-9+\/=]{16,}/' => 'base64:***',
        ];
        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function json(mixed $data): string
    {
        $masked = is_array($data) ? self::array($data) : self::string((string) $data);
        return (string) json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
