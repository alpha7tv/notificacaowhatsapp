<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Settings;
use App\Integrations\EvolutionClient;

/**
 * Anexos enviados logo após a mensagem de cobrança:
 *   1) fatura em PDF (baixada do link do SGP)
 *   2) imagem do QR Code PIX (gerada com o "qrencode" do Ubuntu)
 *   3) código PIX copia e cola SOZINHO numa mensagem (fácil de copiar no celular)
 * Falhas nos anexos são registradas, mas não desfazem o envio do texto principal.
 */
final class InvoiceAttachments
{
    private const MAX_PDF_BYTES = 5 * 1024 * 1024;

    /** @return string[] anexos enviados (ex.: ['pdf','qr','pix']) */
    public static function send(EvolutionClient $wa, string $to, array $invoice): array
    {
        $sent = [];
        $due = date('d-m-Y', strtotime((string) $invoice['due_date']));
        $company = (string) Settings::get('company_name', 'Fiber Link');
        $pix = trim((string) ($invoice['pix_code'] ?? ''));

        if (Settings::bool('attach_pdf', true) && !empty($invoice['pdf_url'])) {
            $pdf = self::downloadPdf((string) $invoice['pdf_url']);
            if ($pdf !== null) {
                $name = 'Fatura-' . preg_replace('/[^A-Za-z0-9]+/', '-', $company) . '-venc-' . $due . '.pdf';
                $r = $wa->sendMedia($to, 'document', 'application/pdf', $pdf, $name, '📄 Fatura com vencimento em ' . str_replace('-', '/', $due));
                if ($r['ok']) {
                    $sent[] = 'pdf';
                }
                usleep(random_int(3000000, 8000000)); // pausa variável entre as partes, como uma pessoa enviando
            }
        }

        if ($pix !== '' && Settings::bool('attach_pix_qr', true)) {
            $png = self::qrPng($pix);
            if ($png !== null) {
                $caption = '📱 *PIX — R$ ' . fmt_money($invoice['amount']) . "*\nAbra o app do seu banco, escolha *Pagar com PIX / QR Code* e aponte a câmera."
                    . (Settings::bool('attach_pix_code', true) ? "\n\nO código *copia e cola* vem na próxima mensagem 👇" : '');
                $r = $wa->sendMedia($to, 'image', 'image/png', $png, 'pix-qrcode.png', $caption);
                if ($r['ok']) {
                    $sent[] = 'qr';
                }
                usleep(random_int(3000000, 8000000)); // pausa variável entre as partes, como uma pessoa enviando
            }
        }

        if ($pix !== '' && Settings::bool('attach_pix_code', true)) {
            // Somente o código, sem nenhum texto junto: o cliente toca, segura e copia.
            $r = $wa->sendText($to, $pix);
            if ($r['ok']) {
                $sent[] = 'pix';
            }
        }
        return $sent;
    }

    public static function downloadPdf(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url)) {
            return null;
        }
        $r = HttpClient::request('GET', $url, ['Accept' => 'application/pdf'], null, 40);
        if ($r['error'] || $r['status'] !== 200 || !str_starts_with($r['body'], '%PDF') || strlen($r['body']) > self::MAX_PDF_BYTES) {
            Logger::warning('sgp', 'Não foi possível baixar o PDF da fatura', ['status' => $r['status'], 'error' => $r['error']]);
            return null;
        }
        return $r['body'];
    }

    /** Gera o PNG do QR Code com o programa "qrencode" (sem shell: argumentos em array). */
    public static function qrPng(string $text): ?string
    {
        $bin = self::qrencodeBinary();
        if ($bin === null || strlen($text) > 1000) {
            return null;
        }
        $proc = @proc_open([$bin, '-o', '-', '-t', 'PNG', '-s', '10', '-m', '3', '-l', 'M', $text], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $png = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 && is_string($png) && str_starts_with($png, "\x89PNG") ? $png : null;
    }

    public static function qrencodeBinary(): ?string
    {
        foreach (['/usr/bin/qrencode', '/usr/local/bin/qrencode'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        return null;
    }
}
