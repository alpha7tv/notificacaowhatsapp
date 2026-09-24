<?php
declare(strict_types=1);

namespace App\Integrations;

use App\Services\Phone;

/**
 * Interpreta respostas do SGP de forma tolerante (nomes de campos variam entre versões).
 * Procura recursivamente objetos que se parecem com contratos/títulos.
 */
final class SgpParser
{
    private const CONTRACT_ID = ['contratoId', 'contrato_id', 'idContrato', 'contrato'];
    private const DUE_KEYS = ['dataVencimento', 'vencimento', 'data_vencimento', 'vencimentoOriginal', 'dataVencimentoOriginal', 'dt_vencimento'];
    private const AMOUNT_KEYS = ['valor', 'valorCorrigido', 'valor_corrigido', 'valorOriginal', 'valor_original', 'valorDocumento', 'valor_titulo'];
    private const TITLE_ID = ['id', 'titulo', 'tituloId', 'idTitulo', 'titulo_id', 'numeroDocumento', 'numero_documento', 'nossoNumero', 'nosso_numero', 'documento'];

    /** @return array{name:string,document:string,phones:string[],email:?string,contracts:array<int,array{sgp_id:string,plan:?string,status:string,status_raw:?string}>} */
    public static function customer(array $json): array
    {
        $contracts = [];
        $name = '';
        $doc = '';
        $phones = [];
        $email = null;
        foreach (self::collect($json, static fn (array $a) => self::first($a, self::CONTRACT_ID) !== null) as $c) {
            $name = $name ?: (string) (self::first($c, ['razaoSocial', 'razao_social', 'nome', 'cliente', 'nomeCliente']) ?? '');
            $doc = $doc ?: preg_replace('/\D/', '', (string) (self::first($c, ['cpfCnpj', 'cpfcnpj', 'cpf_cnpj', 'cpf', 'cnpj']) ?? ''));
            $phones = array_merge($phones, self::contacts($c, ['telefones', 'celulares', 'telefone', 'celular', 'fone', 'whatsapp']));
            $mails = self::contacts($c, ['emails', 'email']);
            $email = $email ?: ($mails[0] ?? null);
            $statusRaw = (string) (self::first($c, ['contratoStatusDisplay', 'status_display', 'statusContrato', 'contratoStatus', 'status', 'situacao']) ?? '');
            $id = (string) self::first($c, self::CONTRACT_ID);
            if ($id === '' || is_array(self::first($c, self::CONTRACT_ID))) {
                continue;
            }
            $contracts[$id] = [
                'sgp_id' => $id,
                'plan' => self::first($c, ['servico_plano', 'plano', 'planoNome', 'servicoPlano']),
                'status' => self::contractStatus($statusRaw),
                'status_raw' => $statusRaw !== '' ? mb_substr($statusRaw, 0, 80) : null,
            ];
        }
        if ($name === '') {
            $name = (string) (self::first($json, ['razaoSocial', 'nome', 'cliente']) ?? '');
        }
        return ['name' => $name, 'document' => $doc, 'phones' => array_values(array_unique($phones)), 'email' => $email, 'contracts' => array_values($contracts)];
    }

    /** @return array<int,array{sgp_id:string,contract:?string,amount:float,due_date:string,status:string,status_raw:?string,paid_at:?string,paid_amount:?float,barcode:?string,pix_code:?string,pdf_url:?string}> */
    public static function titles(array $json): array
    {
        $out = [];
        $items = self::collect($json, static fn (array $a) => self::first($a, self::DUE_KEYS) !== null && self::first($a, self::AMOUNT_KEYS) !== null);
        foreach ($items as $t) {
            $id = self::first($t, self::TITLE_ID);
            $due = self::date((string) self::first($t, self::DUE_KEYS));
            if ($id === null || is_array($id) || $due === null) {
                continue;
            }
            $statusRaw = (string) (self::first($t, ['statusDisplay', 'status', 'situacao', 'status_titulo']) ?? '');
            $paidAt = self::date((string) (self::first($t, ['dataPagamento', 'data_pagamento', 'pagamento', 'dataBaixa', 'data_baixa']) ?? ''), true);
            $status = self::titleStatus($statusRaw, $paidAt !== null);
            $paidAmount = self::first($t, ['valorPago', 'valor_pago']);
            $out[(string) $id] = [
                'sgp_id' => (string) $id,
                'contract' => ($c = self::first($t, self::CONTRACT_ID)) !== null && !is_array($c) ? (string) $c : null,
                'amount' => self::money(self::first($t, self::AMOUNT_KEYS)),
                'due_date' => $due,
                'status' => $status,
                'status_raw' => $statusRaw !== '' ? mb_substr($statusRaw, 0, 80) : null,
                'paid_at' => $status === 'paid' ? ($paidAt ?? date('Y-m-d H:i:s')) : null,
                'paid_amount' => $paidAmount !== null && !is_array($paidAmount) ? self::money($paidAmount) : null,
                'barcode' => self::str(self::first($t, ['linhaDigitavel', 'linha_digitavel', 'codigoBarras', 'codigo_barras']), 120),
                'pix_code' => self::str(self::first($t, ['codigoPix', 'pixCopiaECola', 'pix_copia_cola', 'pixCopiaCola', 'qrcode_pix', 'pix', 'emv']), 2000),
                'pdf_url' => self::url(self::first($t, ['link', 'linkBoleto', 'link_boleto', 'url', 'urlBoleto', 'boleto'])),
            ];
        }
        return array_values($out);
    }

    public static function contractStatus(string $raw): string
    {
        $s = mb_strtolower($raw);
        return match (true) {
            str_contains($s, 'cancel'), str_contains($s, 'inativ'), str_contains($s, 'encerr') => 'cancelled',
            str_contains($s, 'susp'), str_contains($s, 'bloq'), str_contains($s, 'reduz') => 'suspended',
            str_contains($s, 'ativ'), str_contains($s, 'normal'), str_contains($s, 'liberad') => 'active',
            default => 'other',
        };
    }

    public static function titleStatus(string $raw, bool $hasPaidDate): string
    {
        $s = mb_strtolower($raw);
        if (str_contains($s, 'cancel') || str_contains($s, 'estorn')) {
            return 'cancelled';
        }
        if (str_contains($s, 'pag') || str_contains($s, 'quit') || str_contains($s, 'liquid') || str_contains($s, 'recebid') || $hasPaidDate) {
            return 'paid';
        }
        return 'open';
    }

    /** Coleta recursivamente os arrays associativos que satisfazem $match. */
    public static function collect(array $data, callable $match, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }
        $found = [];
        if (!array_is_list($data) && $match($data)) {
            $found[] = $data;
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                array_push($found, ...self::collect($v, $match, $depth + 1));
            }
        }
        return $found;
    }

    public static function first(array $a, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== '') {
                return $a[$k];
            }
        }
        $lower = array_change_key_case($a, CASE_LOWER);
        foreach ($keys as $k) {
            $k = strtolower($k);
            if (array_key_exists($k, $lower) && $lower[$k] !== null && $lower[$k] !== '') {
                return $lower[$k];
            }
        }
        return null;
    }

    private static function contacts(array $a, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $a[$k] ?? null;
            if (is_string($v) || is_int($v)) {
                $out[] = (string) $v;
            } elseif (is_array($v)) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        $c = self::first($item, ['contato', 'numero', 'telefone', 'email', 'valor']);
                        if ($c !== null && !is_array($c)) {
                            $out[] = (string) $c;
                        }
                    } elseif (is_string($item)) {
                        $out[] = $item;
                    }
                }
            }
        }
        return $out;
    }

    public static function date(string $v, bool $withTime = false): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y) || $y < 2000) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if (!$withTime) {
            return $date;
        }
        return sprintf('%s %02d:%02d:%02d', $date, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    public static function money(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 2);
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $v);
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return round((float) $s, 2);
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function url(mixed $v): ?string
    {
        $s = self::str($v, 500);
        return $s !== null && preg_match('#^https?://#i', $s) ? $s : null;
    }

    public static function bestPhone(array $phones): ?string
    {
        return Phone::best($phones);
    }
}
