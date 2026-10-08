<?php
/**
 * E-mail de promoção manual (documento completo, mesmo visual da sequência).
 * @var string $subject @var string $title @var string $body @var string $cta @var string $waUrl @var string $unsubUrl @var string $sender @var string $role
 */
$site = (string) setting('site_name', 'Dominius Play');
$logo = url(asset('img/brand/dominius-logo-horizontal-dark.png'));
$font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
$grad = 'background-color:#FF7A2F;background-image:linear-gradient(90deg,#FFC94D,#FF7A2F 55%,#FF4D6D);';
$initial = mb_strtoupper(mb_substr($sender, 0, 1));
// Texto estilo WhatsApp → HTML: parágrafos, *negrito*, links e quebras de linha
$paragraphs = [];
foreach (preg_split('/\n{2,}/u', trim(str_replace("\r", '', $body))) ?: [] as $para) {
    $esc = e($para);
    $esc = preg_replace('/\*(.+?)\*/su', '<strong style="color:#FFFFFF;font-weight:700;">$1</strong>', $esc) ?? $esc;
    $esc = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" style="color:#FFC94D;text-decoration:underline;word-break:break-all;">$1</a>', $esc) ?? $esc;
    $paragraphs[] = nl2br($esc, false);
}
$preheader = mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $body)) ?? ''), 0, 110);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="supported-color-schemes" content="dark light">
<title><?= e($subject) ?></title>
<style>
  @media only screen and (max-width:480px) {
    .px { padding-left:20px !important; padding-right:20px !important; }
    .h1 { font-size:27px !important; line-height:1.2 !important; }
    .btn a { display:block !important; text-align:center !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#070A12;<?= $font ?>color:#EAEEF7;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;"><?= e($preheader) ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#070A12;">
<tr><td align="center" style="padding:22px 12px 34px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;">

  <tr><td style="padding:2px 6px 18px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
      <td align="left" valign="middle"><img src="<?= e($logo) ?>" width="190" alt="<?= e($site) ?>" style="display:block;width:190px;max-width:60%;height:auto;border:0;"></td>
      <td align="right" valign="middle" style="<?= $font ?>font-size:12px;color:#8E98B0;letter-spacing:.04em;text-transform:uppercase;">Oferta especial</td>
    </tr></table>
  </td></tr>

  <tr><td style="background:#0F1628;border:1px solid #1E2842;border-radius:22px;overflow:hidden;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
      <tr><td style="height:5px;line-height:5px;font-size:0;<?= $grad ?>">&nbsp;</td></tr>

      <tr><td class="px" style="padding:30px 32px 6px;">
        <div style="<?= $font ?>font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#FF7A2F;margin:0 0 12px;">Para você</div>
        <h1 class="h1" style="<?= $font ?>margin:0;font-size:32px;line-height:1.18;font-weight:800;color:#FFFFFF;letter-spacing:-.01em;"><?= e($title) ?></h1>
      </td></tr>

      <tr><td class="px" style="padding:20px 32px 6px;<?= $font ?>">
<?php foreach ($paragraphs as $p): ?>
        <p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#C3CADB;"><?= $p ?></p>
<?php endforeach; ?>
      </td></tr>

      <tr><td class="px" style="padding:6px 32px 8px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #1E2842;"><tr><td style="padding:24px 0 0;">
          <table role="presentation" cellspacing="0" cellpadding="0" width="100%"><tr>
            <td class="btn" style="border-radius:14px;background:#25D366;" align="center">
              <a href="<?= e($waUrl) ?>" style="display:inline-block;padding:16px 28px;<?= $font ?>font-size:15px;font-weight:800;letter-spacing:.02em;color:#04230F;text-decoration:none;">&#128172;&nbsp; <?= e($cta) ?></a>
            </td>
          </tr></table>
        </td></tr></table>
      </td></tr>

      <tr><td class="px" style="padding:22px 32px 30px;">
        <table role="presentation" cellspacing="0" cellpadding="0"><tr>
          <td width="46" valign="middle"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td width="42" height="42" align="center" valign="middle" style="width:42px;height:42px;border-radius:21px;<?= $grad ?>font-size:18px;font-weight:800;color:#1A0E05;line-height:42px;"><?= e($initial) ?></td></tr></table></td>
          <td valign="middle" style="padding-left:12px;<?= $font ?>">
            <div style="font-size:15px;font-weight:700;color:#FFFFFF;"><?= e($sender) ?></div>
            <div style="font-size:13px;color:#8E98B0;"><?= e($role) ?> &middot; <?= e($site) ?></div>
          </td>
        </tr></table>
      </td></tr>
    </table>
  </td></tr>

  <tr><td align="center" style="padding:22px 14px 0;<?= $font ?>font-size:12px;line-height:1.7;color:#7F89A3;">
    Você recebe este e-mail porque pediu um teste em <a href="<?= e(url('')) ?>" style="color:#C3CADB;text-decoration:underline;"><?= e(parse_url(url(''), PHP_URL_HOST) ?: $site) ?></a> e aceitou receber ofertas.<br>
    <a href="<?= e($unsubUrl) ?>" style="color:#C3CADB;text-decoration:underline;">Parar de receber</a> &nbsp;&middot;&nbsp;
    <a href="<?= e(url('privacidade')) ?>" style="color:#C3CADB;text-decoration:underline;">Política de Privacidade</a>
    <div style="margin-top:10px;color:#5E6985;">&copy; <?= date('Y') ?> <?= e($site) ?></div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
