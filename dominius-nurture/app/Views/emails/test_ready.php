<?php
/**
 * E-mail "Seu teste está pronto": dados de acesso + listas (M3U/HLS/SSIPTV) + aplicativos e instruções.
 * @var string $name @var array $result @var bool $hasCredentials
 */
$h = static fn(?string $s): string => e((string) $s);
$linkify = static fn(string $escaped): string => preg_replace(
    '~(https?://[^\s<]+)~u',
    '<a href="$1" style="color:#FFC94D;text-decoration:underline;word-break:break-all;">$1</a>',
    $escaped
) ?? $escaped;

$user = (string) ($result['username'] ?? '');
$pass = (string) ($result['password'] ?? '');
$server = rtrim((string) ($result['server'] ?? ''), '/');
$raw = str_replace("\r", '', (string) ($result['message'] ?? ''));

// Texto do fornecedor → blocos e listas
$lists = [];
$blocks = [];
foreach (preg_split('/\n{2,}/u', $raw) ?: [] as $blk) {
    $lines = [];
    foreach (explode("\n", $blk) as $ln) {
        $c = trim(str_replace('*', '', $ln));
        $c = trim(preg_replace('/^_+|_+$/u', '', $c) ?? $c);
        if ($c === '') {
            continue;
        }
        $t = preg_replace('/^[^\p{L}\p{N}]+/u', '', $c) ?? $c;
        if (preg_match('/^Link\s*(Curto\s*)?\(([A-Za-z0-9+ ]+)\)\s*:\s*(https?:\/\/\S+)/iu', $t, $m)) {
            $lists[] = [trim(($m[1] !== '' ? 'Link curto' : 'Link') . ' (' . strtoupper(trim($m[2])) . ')'), $m[3], strtoupper(trim($m[2]))];
            continue;
        }
        $lines[] = $c;
    }
    if ($lines) {
        $blocks[] = $lines;
    }
}
// Fallback: monta M3U/HLS padrão quando o fornecedor não mandou as listas
if (!$lists && $server !== '' && $user !== '' && $pass !== '') {
    $q = 'username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&type=m3u_plus';
    $lists[] = ['Link (M3U)', $server . '/get.php?' . $q . '&output=mpegts', 'M3U'];
    $lists[] = ['Link (HLS)', $server . '/get.php?' . $q . '&output=hls', 'HLS'];
}
$dot = ['M3U' => '#25D366', 'HLS' => '#FFC94D', 'SSIPTV' => '#FF4D6D'];

$row = static function (string $label, ?string $value, bool $mono = true) use ($h): string {
    if ($value === null || $value === '') {
        return '';
    }
    return '<tr><td style="padding:12px 14px;border-bottom:1px solid #1E2842;color:#8E98B0;font-size:13px;width:34%;">' . e($label) . '</td>'
        . '<td style="padding:12px 14px;border-bottom:1px solid #1E2842;color:#FFFFFF;font-size:15px;font-weight:700;word-break:break-all;' . ($mono ? 'font-family:Consolas,Menlo,monospace;' : '') . '">' . e($value) . '</td></tr>';
};
$heading = static fn(string $t): string => '<div style="margin:26px 0 12px;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#FF7A2F;">' . e($t) . '</div>';
?>
<h1 style="margin:0 0 12px;font-size:26px;line-height:1.25;color:#FFFFFF;">Seu teste está pronto, <?= $h($name) ?>! 🎉</h1>
<p style="margin:0 0 6px;font-size:16px;line-height:1.6;color:#C3CADB;">Estes são os seus dados de acesso. Guarde este e-mail em local seguro e <strong style="color:#FFFFFF;">não compartilhe</strong> seus dados.</p>

<?= $heading('Seus dados de acesso') ?>
<?php if ($user !== '' || $server !== '' || !empty($result['url'])): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #1E2842;border-radius:14px;background:#0A0F1C;margin:0 0 6px;border-collapse:separate;overflow:hidden;">
  <?= $row('Usuário', $user) ?>
  <?= $row('Senha', $pass) ?>
  <?= $row('Servidor', $server) ?>
  <?= $row('URL', $result['url'] ?? null) ?>
  <?= $row('Vencimento', $result['expires'] ?? null, false) ?>
</table>
<?php endif; ?>

<?php if ($lists): ?>
<?= $heading('Listas para o seu aplicativo') ?>
<p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#8E98B0;">Copie o link da lista e cole no seu aplicativo. No celular, toque e segure o link para copiar.</p>
<?php foreach ($lists as [$label, $url, $kind]): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 10px;border:1px solid #1E2842;border-radius:12px;background:#0A0F1C;border-collapse:separate;">
  <tr><td style="padding:12px 14px 4px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#C3CADB;"><span style="color:<?= $dot[$kind] ?? '#8E98B0' ?>;">&#9679;</span>&nbsp; <?= $h($label) ?></td></tr>
  <tr><td style="padding:0 14px 13px;font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.5;word-break:break-all;"><a href="<?= $h($url) ?>" style="color:#FFC94D;text-decoration:none;"><?= $h($url) ?></a></td></tr>
</table>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($blocks): ?>
<?= $heading('Aplicativos e instruções') ?>
<?php foreach ($blocks as $lines): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 10px;border:1px solid #1E2842;border-radius:12px;background:#0F1628;border-collapse:separate;">
  <tr><td style="padding:12px 14px;font-size:14px;line-height:1.75;color:#C3CADB;word-break:break-word;">
<?php foreach ($lines as $i => $line):
    $esc = e($line);
    $plain = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $line) ?? $line);
    $isTitle = $i === 0 && !str_contains($line, ':') && !str_contains($line, 'http') && mb_strtoupper($plain) === $plain && mb_strlen($plain) > 6;
    if ($isTitle) {
        echo '<span style="display:block;margin:0 0 4px;font-size:12.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#FFFFFF;">' . $esc . '</span>';
        continue;
    }
    if (preg_match('/^([^:]{1,40}):\s*(.*)$/u', $line, $m) && !preg_match('~https?$~i', $m[1])) {
        $esc = '<strong style="color:#FFFFFF;">' . e($m[1]) . ':</strong> ' . e($m[2]);
    }
    echo $linkify($esc), $i < count($lines) - 1 ? '<br>' : '';
endforeach; ?>
  </td></tr>
</table>
<?php endforeach; ?>
<?php elseif (!$hasCredentials && $raw !== ''): ?>
<div style="border:1px solid #1E2842;border-radius:14px;background:#0A0F1C;padding:18px;margin:0 0 20px;font-family:Consolas,Menlo,monospace;font-size:14px;line-height:1.7;color:#EAEEF7;white-space:pre-wrap;word-break:break-word;"><?= nl2br(e($raw)) ?></div>
<?php elseif (!empty($result['notes'])): ?>
<p style="margin:0 0 18px;font-size:14px;color:#C3CADB;"><?= $h($result['notes']) ?></p>
<?php endif; ?>

<?= $heading('Próximos passos') ?>
<ol style="margin:0 0 24px;padding-left:20px;font-size:15px;line-height:1.8;color:#C3CADB;">
  <li>Instale o aplicativo indicado para o seu aparelho: <a href="<?= $h(url('aplicativos')) ?>" style="color:#FFC94D;">ver aplicativos</a>.</li>
  <li>Entre com o usuário e a senha acima, ou cole a lista no aplicativo.</li>
  <li>Gostou? Veja como <strong style="color:#FFFFFF;">revender</strong> e ganhar com a sua própria carteira de clientes.</li>
</ol>
<table role="presentation" cellspacing="0" cellpadding="0"><tr>
  <td style="border-radius:999px;background:#FF7A2F;background-image:linear-gradient(135deg,#FFC94D,#FF7A2F 55%,#FF4D6D);"><a href="<?= $h(url('mensalista')) ?>" style="display:inline-block;padding:13px 24px;font-size:14px;font-weight:700;color:#1A0E05;text-decoration:none;text-transform:uppercase;">Ver planos de revenda</a></td>
  <td style="width:10px"></td>
  <td style="border-radius:999px;border:1px solid #2A3456;"><a href="<?= $h(str_starts_with(support_link(), 'http') ? support_link() : url(support_link())) ?>" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:700;color:#EAEEF7;text-decoration:none;text-transform:uppercase;">Falar com suporte</a></td>
</tr></table>
