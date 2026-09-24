<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return App::env($key, $default);
    }
}

/** Escapa texto para HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function storage_path(string $sub = ''): string
{
    return rtrim(App::storagePath(), '/\\') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $value, string $format = 'd/m/Y'): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

function fmt_datetime(?string $value): string
{
    return fmt_date($value, 'd/m/Y H:i');
}

function fmt_money(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function fmt_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

function fmt_duration(int $seconds): string
{
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($d) {
        $parts[] = $d . 'd';
    }
    if ($h || $d) {
        $parts[] = $h . 'h';
    }
    $parts[] = $m . 'min';
    return implode(' ', $parts);
}

/** Tempo decorrido legível ("há 3 min"). */
function ago(?string $datetime): string
{
    if (!$datetime) {
        return 'nunca';
    }
    $diff = time() - (int) strtotime($datetime);
    if ($diff < 0) {
        return 'agora';
    }
    if ($diff < 60) {
        return "há {$diff}s";
    }
    if ($diff < 3600) {
        return 'há ' . intdiv($diff, 60) . ' min';
    }
    if ($diff < 86400) {
        return 'há ' . intdiv($diff, 3600) . ' h';
    }
    return 'há ' . intdiv($diff, 86400) . ' dia(s)';
}

function mask_phone(?string $phone): string
{
    $p = preg_replace('/\D/', '', (string) $phone);
    if (strlen($p) < 8) {
        return $p === '' ? '—' : '***';
    }
    return substr($p, 0, 4) . str_repeat('*', max(0, strlen($p) - 8)) . substr($p, -4);
}

function mask_document(?string $doc): string
{
    $d = preg_replace('/\D/', '', (string) $doc);
    if ($d === '') {
        return '—';
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 3) . '.***.***-' . substr($d, -2);
    }
    if (strlen($d) === 14) {
        return substr($d, 0, 2) . '.***.***/****-' . substr($d, -2);
    }
    return '***' . substr($d, -2);
}

function status_badge(string $status): string
{
    $map = [
        'pending' => ['Pendente', 'warn'], 'processing' => ['Enviando', 'info'], 'sent' => ['Enviada', 'ok'],
        'delivered' => ['Entregue', 'ok'], 'read' => ['Lida', 'ok'], 'failed' => ['Falhou', 'bad'],
        'cancelled' => ['Cancelada', 'muted'], 'skipped' => ['Ignorada', 'muted'],
        'open' => ['Em aberto', 'warn'], 'paid' => ['Paga', 'ok'],
        'active' => ['Ativo', 'ok'], 'suspended' => ['Suspenso', 'bad'], 'other' => ['Outro', 'muted'],
        'ok' => ['OK', 'ok'], 'error' => ['Erro', 'bad'], 'running' => ['Executando', 'info'],
        'queued' => ['Na fila', 'warn'], 'success' => ['Sucesso', 'ok'],
        'processed' => ['Processado', 'ok'], 'duplicate' => ['Duplicado', 'muted'], 'ignored' => ['Ignorado', 'muted'],
        'rejected' => ['Rejeitado', 'bad'], 'partial' => ['Parcial', 'warn'], 'skipped_task' => ['Pulada', 'muted'], 'received' => ['Recebido', 'info'], 'rolled_back' => ['Revertido', 'bad'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'muted'];
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}

function event_label(string $event): string
{
    return [
        'before_due' => 'Antes do vencimento',
        'due_today' => 'No dia do vencimento',
        'after_due' => 'Após o vencimento (atraso)',
        'payment_confirmed' => 'Pagamento confirmado',
        'suspended' => 'Contrato suspenso',
        'cancelled' => 'Contrato cancelado',
        'reactivated' => 'Contrato reativado',
        'test' => 'Teste',
    ][$event] ?? $event;
}
