<?php
/**
 * E-mail da sequência de nutrição (documento completo, não usa emails.layout).
 * @var string $subject @var string $pre @var string $eyebrow @var string $title @var array $blocks
 * @var string $cta @var string $waUrl @var ?array $link @var string $unsubUrl @var string $sender @var string $role
 * @var int $stepNumber @var int $stepTotal
 */
$site = (string) setting('site_name', 'Dominius Play');
$logo = url(asset('img/brand/dominius-logo-horizontal-dark.png'));
$rich = static fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/s', '<strong style="color:#FFFFFF;font-weight:700;">$1</strong>', e($s)) ?? e($s);
$hero = static fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/s', '<span style="color:#FFC94D;">$1</span>', e($s)) ?? e($s);
$font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
$grad = 'background-color:#FF7A2F;background-image:linear-gradient(90deg,#FFC94D,#FF7A2F 55%,#FF4D6D);';
$initial = mb_strtoupper(mb_substr($sender, 0, 1));
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
    .stat { display:block !important; width:auto !important; margin:0 0 10px 0 !important; }
    .stat-gap { display:none !important; }
    .btn a { display:block !important; text-align:center !important; }
    .btn2 { display:block !important; width:100% !important; padding:10px 0 0 0 !important; }
    .sim-big { font-size:26px !important; }
    .pl td { padding-left:8px !important; padding-right:6px !important; font-size:13px !important; }
    .pl .nm { white-space:nowrap !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#070A12;<?= $font ?>color:#EAEEF7;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;"><?= e($pre) ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#070A12;">
<tr><td align="center" style="padding:22px 12px 34px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;">

  <!-- Topo -->
  <tr><td style="padding:2px 6px 18px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
      <td align="left" valign="middle"><img src="<?= e($logo) ?>" width="190" alt="<?= e($site) ?>" style="display:block;width:190px;max-width:60%;height:auto;border:0;"></td>
      <td align="right" valign="middle" style="<?= $font ?>font-size:12px;color:#8E98B0;letter-spacing:.04em;text-transform:uppercase;">Revenda &middot; <?= (int) $stepNumber ?>/<?= (int) $stepTotal ?></td>
    </tr></table>
  </td></tr>

  <!-- Cartão principal -->
  <tr><td style="background:#0F1628;border:1px solid #1E2842;border-radius:22px;overflow:hidden;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
      <tr><td style="height:5px;line-height:5px;font-size:0;<?= $grad ?>">&nbsp;</td></tr>

      <!-- Progresso -->
      <tr><td class="px" style="padding:20px 32px 0;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
          <?php for ($i = 1; $i <= $stepTotal; $i++): ?>
          <td style="height:4px;line-height:4px;font-size:0;border-radius:4px;background:<?= $i <= $stepNumber ? '#FF7A2F' : '#1E2842' ?>;">&nbsp;</td>
          <?php if ($i < $stepTotal): ?><td style="width:4px;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
          <?php endfor; ?>
        </tr></table>
      </td></tr>

      <!-- Título -->
      <tr><td class="px" style="padding:26px 32px 4px;">
        <div style="<?= $font ?>font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#FF7A2F;margin:0 0 12px;"><?= e($eyebrow) ?></div>
        <h1 class="h1" style="<?= $font ?>margin:0;font-size:32px;line-height:1.18;font-weight:800;color:#FFFFFF;letter-spacing:-.01em;"><?= $hero($title) ?></h1>
      </td></tr>

      <!-- Conteúdo -->
      <tr><td class="px" style="padding:20px 32px 6px;<?= $font ?>">
<?php foreach ($blocks as $bi => $b): ?>
<?php if ($b['type'] === 'p'): ?>
        <p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#C3CADB;"><?= $rich($b['text']) ?></p>

<?php elseif ($b['type'] === 'note'): ?>
        <p style="margin:0 0 18px;font-size:12.5px;line-height:1.6;color:#8E98B0;"><?= $rich($b['text']) ?></p>

<?php elseif ($b['type'] === 'callout'): ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:4px 0 22px;"><tr>
          <td style="width:4px;border-radius:4px;<?= $grad ?>font-size:0;">&nbsp;</td>
          <td style="background:#151E36;border-radius:0 14px 14px 0;padding:16px 18px;font-size:15px;line-height:1.65;color:#E2E7F3;"><?= $rich($b['text']) ?></td>
        </tr></table>

<?php elseif ($b['type'] === 'list'): ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 18px;">
<?php foreach ($b['items'] as $item): ?>
          <tr>
            <td width="34" valign="top" style="padding:3px 0 12px;"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td width="22" height="22" align="center" valign="middle" style="width:22px;height:22px;border-radius:11px;background:#FF7A2F;color:#1A0E05;font-size:13px;font-weight:800;line-height:22px;">&#10003;</td></tr></table></td>
            <td valign="top" style="padding:0 0 12px;font-size:15.5px;line-height:1.6;color:#C3CADB;"><?= $rich($item) ?></td>
          </tr>
<?php endforeach; ?>
        </table>

<?php elseif ($b['type'] === 'steps'): ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 18px;">
<?php foreach ($b['items'] as $n => $item): ?>
          <tr>
            <td width="44" valign="top" style="padding:0 0 14px;"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td width="32" height="32" align="center" valign="middle" style="width:32px;height:32px;border-radius:16px;background:#1E2842;border:1px solid #FF7A2F;color:#FFC94D;font-size:15px;font-weight:800;line-height:32px;"><?= $n + 1 ?></td></tr></table></td>
            <td valign="top" style="padding:5px 0 14px;font-size:15.5px;line-height:1.6;color:#C3CADB;"><?= $rich($item) ?></td>
          </tr>
<?php endforeach; ?>
        </table>

<?php elseif ($b['type'] === 'stats'): ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:2px 0 22px;"><tr>
<?php foreach ($b['items'] as $si => $s): ?>
<?php if ($si > 0): ?>          <td class="stat-gap" width="10" style="font-size:0;line-height:0;">&nbsp;</td>
<?php endif; ?>
          <td class="stat" width="<?= (int) floor(100 / max(1, count($b['items']))) ?>%" valign="top" style="background:#0A0F1C;border:1px solid #1E2842;border-radius:14px;padding:16px 10px;text-align:center;">
            <div style="font-size:24px;line-height:1.15;font-weight:800;color:#FFC94D;"><?= e($s['value']) ?></div>
            <div style="margin-top:6px;font-size:12px;line-height:1.4;color:#8E98B0;"><?= e($s['label']) ?></div>
          </td>
<?php endforeach; ?>
        </tr></table>

<?php elseif ($b['type'] === 'plans'): ?>
        <table class="pl" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border:1px solid #1E2842;border-radius:14px;background:#0A0F1C;border-collapse:separate;overflow:hidden;">
          <tr>
            <td style="padding:11px 14px;background:#151E36;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8E98B0;font-weight:700;">Plano</td>
            <td align="right" style="padding:11px 8px;background:#151E36;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8E98B0;font-weight:700;">Créditos</td>
            <td align="right" style="padding:11px 8px;background:#151E36;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8E98B0;font-weight:700;">Valor</td>
            <td align="right" style="padding:11px 14px 11px 8px;background:#151E36;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8E98B0;font-weight:700;">Por crédito</td>
          </tr>
<?php foreach ($b['rows'] as $r): $best = !empty($r['best']); ?>
          <tr>
            <td class="nm" style="padding:13px 14px;border-top:1px solid #1E2842;font-size:14.5px;color:#EAEEF7;<?= $best ? 'background:#1B2440;' : '' ?>">
              <?= e($r['name']) ?>
              <?php if (!empty($r['tag'])): ?><span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;<?= $grad ?>font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#1A0E05;"><?= e($r['tag']) ?></span><?php endif; ?>
            </td>
            <td align="right" style="padding:13px 8px;border-top:1px solid #1E2842;font-size:14.5px;color:#C3CADB;<?= $best ? 'background:#1B2440;' : '' ?>"><?= (int) $r['credits'] ?></td>
            <td align="right" style="padding:13px 8px;border-top:1px solid #1E2842;font-size:15px;font-weight:800;color:#FFFFFF;white-space:nowrap;<?= $best ? 'background:#1B2440;' : '' ?>"><?= e(money($r['price'], true)) ?><?= $b['kind'] === 'mensalista' ? '<span style="color:#8E98B0;font-weight:400;font-size:12px;">/mês</span>' : '' ?></td>
            <td align="right" style="padding:13px 14px 13px 8px;border-top:1px solid #1E2842;font-size:14.5px;font-weight:700;color:#FFC94D;white-space:nowrap;<?= $best ? 'background:#1B2440;' : '' ?>"><?= e(money($r['per_credit'], true)) ?></td>
          </tr>
<?php endforeach; ?>
        </table>

<?php elseif ($b['type'] === 'sim'): ?>
<?php foreach ($b['rows'] as $r): ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 12px;border:1px solid #1E2842;border-radius:14px;background:#0A0F1C;border-collapse:separate;">
          <tr>
            <td style="padding:16px 18px 6px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
                <td valign="middle" style="font-size:13px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;color:#8E98B0;"><span style="font-size:26px;font-weight:800;color:#FFFFFF;letter-spacing:0;text-transform:none;"><?= (int) $r['clients'] ?></span> clientes</td>
                <td align="right" valign="middle"><div class="sim-big" style="font-size:30px;line-height:1.1;font-weight:800;color:#FFC94D;"><?= e(money($r['profit'], true)) ?></div><div style="font-size:11px;color:#8E98B0;letter-spacing:.04em;text-transform:uppercase;">sobra por mês</div></td>
              </tr></table>
            </td>
          </tr>
          <tr><td style="padding:6px 18px 16px;font-size:12.5px;line-height:1.5;color:#8E98B0;">Plano <strong style="color:#C3CADB;"><?= e($r['plan']) ?></strong> &middot; custo <?= e(money($r['cost'], true)) ?> &middot; faturamento <?= e(money($r['revenue'], true)) ?></td></tr>
        </table>
<?php endforeach; ?>
        <div style="height:6px;line-height:6px;font-size:0;">&nbsp;</div>

<?php endif; ?>
<?php endforeach; ?>
      </td></tr>

      <!-- Chamada para ação -->
      <tr><td class="px" style="padding:6px 32px 8px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #1E2842;"><tr><td style="padding:24px 0 0;">
          <table role="presentation" cellspacing="0" cellpadding="0" width="100%"><tr>
            <td class="btn" style="border-radius:14px;background:#25D366;" align="center">
              <a href="<?= e($waUrl) ?>" style="display:inline-block;padding:16px 28px;<?= $font ?>font-size:15px;font-weight:800;letter-spacing:.02em;color:#04230F;text-decoration:none;">&#128172;&nbsp; <?= e($cta) ?></a>
            </td>
          </tr></table>
<?php if ($link): ?>
          <table role="presentation" cellspacing="0" cellpadding="0" width="100%" style="margin-top:12px;"><tr>
            <td align="center" style="border-radius:14px;border:1px solid #2A3456;">
              <a href="<?= e($link['url']) ?>" style="display:block;padding:13px 22px;<?= $font ?>font-size:14px;font-weight:700;color:#EAEEF7;text-decoration:none;"><?= e($link['label']) ?> &rarr;</a>
            </td>
          </tr></table>
<?php endif; ?>
        </td></tr></table>
      </td></tr>

      <!-- Assinatura -->
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

  <!-- Rodapé -->
  <tr><td align="center" style="padding:22px 14px 0;<?= $font ?>font-size:12px;line-height:1.7;color:#7F89A3;">
    Você recebe este e-mail porque pediu um teste em <a href="<?= e(url('')) ?>" style="color:#C3CADB;text-decoration:underline;"><?= e(parse_url(url(''), PHP_URL_HOST) ?: $site) ?></a> e aceitou receber informações sobre revenda.<br>
    <a href="<?= e($unsubUrl) ?>" style="color:#C3CADB;text-decoration:underline;">Parar de receber</a> &nbsp;&middot;&nbsp;
    <a href="<?= e(url('privacidade')) ?>" style="color:#C3CADB;text-decoration:underline;">Política de Privacidade</a>
    <div style="margin-top:10px;color:#5E6985;">&copy; <?= date('Y') ?> <?= e($site) ?></div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
