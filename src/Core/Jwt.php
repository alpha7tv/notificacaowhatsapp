<?php
declare(strict_types=1);

namespace App\Core;

/** JWT HS256 mínimo para a API (/api/v1/auth/login). */
final class Jwt
{
    private static function secret(): string
    {
        $s = (string) App::env('JWT_SECRET', '');
        if (strlen($s) < 32) {
            throw new \RuntimeException('JWT_SECRET ausente ou muito curto.');
        }
        return $s;
    }

    private static function b64(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private static function unb64(string $d): string
    {
        return (string) base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4), true);
    }

    public static function encode(array $claims, int $ttl = 3600): string
    {
        $now = time();
        $claims += ['iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl, 'iss' => (string) App::env('API_URL', 'fiberlink')];
        $h = self::b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64((string) json_encode($claims));
        $s = self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
        return "$h.$p.$s";
    }

    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::unb64($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode(self::unb64($p), true);
        if (!is_array($claims)) {
            return null;
        }
        $now = time();
        if (($claims['exp'] ?? 0) < $now || ($claims['nbf'] ?? 0) > $now + 30) {
            return null;
        }
        return $claims;
    }
}
