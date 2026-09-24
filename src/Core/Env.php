<?php
declare(strict_types=1);

namespace App\Core;

/** Leitor simples e seguro de arquivos .env (não executa nada, apenas lê KEY=VALUE). */
final class Env
{
    public static function parseFile(string $file): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        return $lines === false ? [] : self::parseLines($lines);
    }

    public static function parseLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }
            $value = trim(substr($line, $pos + 1));
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $q = $value[0];
                $end = strrpos($value, $q);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($q === '"') {
                    $value = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
                }
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
