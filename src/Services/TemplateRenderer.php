<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

/**
 * Modelos com variáveis {{nome}} e blocos condicionais {{#pix}} ... {{/pix}}
 * (o bloco só aparece se a variável tiver valor).
 */
final class TemplateRenderer
{
    public const VARIABLES = [
        'nome' => 'Nome completo do cliente',
        'primeiro_nome' => 'Primeiro nome do cliente',
        'documento_mascarado' => 'CPF/CNPJ mascarado',
        'valor' => 'Valor da fatura (1.234,56)',
        'vencimento' => 'Data de vencimento (dd/mm/aaaa)',
        'dias_para_vencer' => 'Dias até o vencimento',
        'dias_atraso' => 'Dias de atraso',
        'linha_digitavel' => 'Linha digitável do boleto',
        'pix' => 'PIX copia e cola',
        'link_boleto' => 'Link do boleto/fatura',
        'contrato' => 'Número do contrato',
        'plano' => 'Plano contratado',
        'data_pagamento' => 'Data do pagamento',
        'valor_pago' => 'Valor pago',
        'empresa' => 'Nome da empresa (Configurações)',
        'telefone_suporte' => 'Telefone de suporte (Configurações)',
    ];

    public static function render(string $template, array $vars): string
    {
        $vars = array_map(static fn ($v) => $v === null ? '' : (string) $v, $vars);
        $out = preg_replace_callback('/\{\{#([a-z_]+)\}\}(.*?)\{\{\/\1\}\}/s', static function ($m) use ($vars) {
            return trim($vars[$m[1]] ?? '') !== '' ? $m[2] : '';
        }, $template);
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn ($m) => $vars[$m[1]] ?? '', (string) $out);
        $out = preg_replace("/\n{3,}/", "\n\n", (string) $out);
        return trim((string) $out);
    }

    /** @return string[] variáveis usadas no modelo que não existem */
    public static function unknownVariables(string $template): array
    {
        preg_match_all('/\{\{\s*[#\/]?([a-z_]+)\s*\}\}/', $template, $m);
        return array_values(array_unique(array_diff($m[1], array_keys(self::VARIABLES))));
    }

    public static function varsFor(?array $customer, ?array $invoice, ?array $contract): array
    {
        $today = new \DateTimeImmutable('today');
        $vars = [
            'empresa' => Settings::get('company_name', 'Fiber Link'),
            'telefone_suporte' => Settings::get('support_phone', ''),
            'nome' => $customer['name'] ?? '',
            'primeiro_nome' => self::firstName($customer['name'] ?? ''),
            'documento_mascarado' => mask_document($customer['document'] ?? ''),
            'contrato' => $contract['sgp_id'] ?? '',
            'plano' => $contract['plan'] ?? '',
        ];
        if ($invoice) {
            $due = new \DateTimeImmutable($invoice['due_date']);
            $diff = (int) $today->diff($due)->format('%r%a');
            $vars += [
                'valor' => fmt_money($invoice['amount']),
                'vencimento' => $due->format('d/m/Y'),
                'dias_para_vencer' => (string) max(0, $diff),
                'dias_atraso' => (string) max(0, -$diff),
                'linha_digitavel' => $invoice['barcode'] ?? '',
                'pix' => $invoice['pix_code'] ?? '',
                'link_boleto' => $invoice['pdf_url'] ?? '',
                'data_pagamento' => $invoice['paid_at'] ? date('d/m/Y', strtotime($invoice['paid_at'])) : '',
                'valor_pago' => fmt_money($invoice['paid_amount'] ?? $invoice['amount']),
            ];
        }
        return $vars;
    }

    public static function sampleVars(): array
    {
        return [
            'empresa' => Settings::get('company_name', 'Fiber Link'),
            'telefone_suporte' => Settings::get('support_phone', '(00) 0000-0000'),
            'nome' => 'Maria da Silva (TESTE)',
            'primeiro_nome' => 'Maria',
            'documento_mascarado' => '123.***.***-09',
            'valor' => '99,90',
            'vencimento' => date('d/m/Y', strtotime('+3 days')),
            'dias_para_vencer' => '3',
            'dias_atraso' => '2',
            'linha_digitavel' => '00190.00009 01234.567891 23456.789012 1 00000000009990',
            'pix' => '00020126580014BR.GOV.BCB.PIX0136TESTE-NAO-PAGAR-1234567890520400005303986540599.905802BR5909FIBERLINK6009SAOPAULO62070503***6304ABCD',
            'link_boleto' => '',
            'contrato' => '0000 (TESTE)',
            'plano' => 'Fibra 500 Mega',
            'data_pagamento' => date('d/m/Y'),
            'valor_pago' => '99,90',
        ];
    }

    private static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'cliente';
        }
        $first = explode(' ', $name)[0];
        return mb_convert_case(mb_strtolower($first), MB_CASE_TITLE);
    }
}
