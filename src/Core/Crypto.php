<?php
declare(strict_types=1);

namespace App\Core;

/** Criptografia simétrica (AES-256-GCM) para segredos salvos no banco (tokens do SGP/Evolution). */
final class Crypto
{
    private static function key(): string
    {
        $k = (string) App::env('APP_KEY', '');
        if ($k === '') {
            throw new \RuntimeException('APP_KEY não configurada no .env');
        }
        if (str_starts_with($k, 'base64:')) {
            $k = (string) base64_decode(substr($k, 7), true);
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Falha ao criptografar.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new \RuntimeException('Formato criptografado desconhecido.');
        }
        $raw = (string) base64_decode(substr($payload, 3), true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Falha ao descriptografar (APP_KEY diferente?).');
        }
        return $plain;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
