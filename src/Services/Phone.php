<?php
declare(strict_types=1);

namespace App\Services;

final class Phone
{
    /** Normaliza telefone brasileiro para o formato 55DDDNUMERO. Retorna null se inválido. */
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if ($d === '') {
            return null;
        }
        $d = ltrim($d, '0');
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = '55' . $d;
        }
        if (!str_starts_with($d, '55') || (strlen($d) !== 12 && strlen($d) !== 13)) {
            return null;
        }
        $ddd = (int) substr($d, 2, 2);
        if ($ddd < 11 || $ddd > 99) {
            return null;
        }
        return $d;
    }

    public static function isMobile(string $normalized): bool
    {
        // 55 + DDD + 9XXXXXXXX (13 dígitos) ou formato antigo de 8 dígitos começando com 6-9
        if (strlen($normalized) === 13) {
            return $normalized[4] === '9';
        }
        return strlen($normalized) === 12 && in_array($normalized[4], ['6', '7', '8', '9'], true);
    }

    /** Escolhe o melhor telefone (celular primeiro) entre vários. */
    public static function best(array $raws): ?string
    {
        $valid = [];
        foreach ($raws as $r) {
            foreach (preg_split('/[\/;,|]+/', (string) $r) as $part) {
                $n = self::normalize($part);
                if ($n) {
                    $valid[] = $n;
                }
            }
        }
        foreach ($valid as $n) {
            if (self::isMobile($n)) {
                return $n;
            }
        }
        return $valid[0] ?? null;
    }
}
