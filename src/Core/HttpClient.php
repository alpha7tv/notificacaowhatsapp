<?php
declare(strict_types=1);

namespace App\Core;

final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @param array|string|null $body array => JSON (ou form, se $form = true)
     * @return array{status:int, body:string, json:?array, error:?string, ms:int}
     */
    public static function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20, bool $form = false): array
    {
        $ch = curl_init();
        $h = [];
        if (is_array($body)) {
            if ($form) {
                $body = http_build_query($body);
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            } else {
                $body = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers['Content-Type'] = 'application/json';
            }
        }
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'FiberLink-Notificacoes/' . App::version()];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $t = microtime(true);
        $resp = curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $text = is_string($resp) ? $resp : '';
        $json = json_decode($text, true);
        return [
            'status' => $status,
            'body' => $text,
            'json' => is_array($json) ? $json : null,
            'error' => $err,
            'ms' => (int) round((microtime(true) - $t) * 1000),
        ];
    }
}
