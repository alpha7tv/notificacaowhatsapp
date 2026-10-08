<?php
/** @var string $pre @var string $title @var array $blocks @var string $cta @var string $waUrl @var ?array $link @var string $unsubUrl @var string $sender */
$rich = static fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/s', '<strong style="color:#FFFFFF;">$1</strong>', e($s)) ?? e($s);
$th = 'padding:10px 12px;border-bottom:1px solid #1E2842;color:#8E98B0;font-size:12px;text-align:left;font-weight:600;';
$td = 'padding:10px 12px;border-bottom:1px solid #1E2842;color:#EAEEF7;font-size:14px;';
?>
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?= e($pre) ?></span>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#FFFFFF;"><?= e($title) ?></h1>
<?php foreach ($blocks as $b): ?>
  <?php if ($b['type'] === 'p'): ?>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#C3CADB;"><?= $rich($b['text']) ?></p>
  <?php elseif ($b['type'] === 'note'): ?>
<p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#8E98B0;"><?= $rich($b['text']) ?></p>
  <?php elseif ($b['type'] === 'list'): ?>
<ul style="margin:0 0 18px;padding-left:20px;font-size:15px;line-height:1.8;color:#C3CADB;">
    <?php foreach ($b['items'] as $item): ?><li><?= $rich($item) ?></li><?php endforeach; ?>
</ul>
  <?php elseif ($b['type'] === 'plans'): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #1E2842;border-radius:12px;background:#0A0F1C;margin:0 0 18px;border-collapse:separate;overflow:hidden;">
  <tr><th style="<?= $th ?>">Plano</th><th style="<?= $th ?>">Créditos</th><th style="<?= $th ?>">Valor</th><th style="<?= $th ?>">Por crédito</th></tr>
    <?php foreach ($b['rows'] as $r): ?>
  <tr>
    <td style="<?= $td ?>"><?= e($r['name']) ?></td>
    <td style="<?= $td ?>"><?= (int) $r['credits'] ?></td>
    <td style="<?= $td ?>font-weight:700;color:#FFFFFF;"><?= e(money($r['price'])) ?><?= $b['kind'] === 'mensalista' ? '<span style="color:#8E98B0;font-weight:400;">/mês</span>' : '' ?></td>
    <td style="<?= $td ?>"><?= e(money($r['per_credit'])) ?></td>
  </tr>
    <?php endforeach; ?>
</table>
  <?php elseif ($b['type'] === 'sim'): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #1E2842;border-radius:12px;background:#0A0F1C;margin:0 0 18px;border-collapse:separate;overflow:hidden;">
  <tr><th style="<?= $th ?>">Clientes</th><th style="<?= $th ?>">Plano</th><th style="<?= $th ?>">Custo</th><th style="<?= $th ?>">Faturamento</th><th style="<?= $th ?>">Sobra</th></tr>
    <?php foreach ($b['rows'] as $r): ?>
  <tr>
    <td style="<?= $td ?>"><?= (int) $r['clients'] ?></td>
    <td style="<?= $td ?>"><?= e($r['plan']) ?></td>
    <td style="<?= $td ?>"><?= e(money($r['cost'])) ?></td>
    <td style="<?= $td ?>"><?= e(money($r['revenue'])) ?></td>
    <td style="<?= $td ?>font-weight:700;color:#FFC94D;"><?= e(money($r['profit'])) ?></td>
  </tr>
    <?php endforeach; ?>
</table>
  <?php endif; ?>
<?php endforeach; ?>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 22px;"><tr>
  <td style="border-radius:999px;background:#FF7A2F;background-image:linear-gradient(135deg,#FFC94D,#FF7A2F 55%,#FF4D6D);"><a href="<?= e($waUrl) ?>" style="display:inline-block;padding:14px 26px;font-size:14px;font-weight:700;color:#1A0E05;text-decoration:none;text-transform:uppercase;"><?= e($cta) ?></a></td>
  <?php if ($link): ?>
  <td style="width:10px"></td>
  <td style="border-radius:999px;border:1px solid #2A3456;"><a href="<?= e($link['url']) ?>" style="display:inline-block;padding:13px 22px;font-size:14px;font-weight:700;color:#EAEEF7;text-decoration:none;text-transform:uppercase;"><?= e($link['label']) ?></a></td>
  <?php endif; ?>
</tr></table>
<p style="margin:0 0 4px;font-size:15px;color:#C3CADB;">Um abraço,</p>
<p style="margin:0 0 22px;font-size:15px;color:#FFFFFF;font-weight:700;"><?= e($sender) ?> · <?= e(setting('site_name', 'Dominius Play')) ?></p>
<p style="margin:0;padding-top:16px;border-top:1px solid #1E2842;font-size:12px;line-height:1.6;color:#8E98B0;">
  Você recebe este e-mail porque pediu um teste no nosso site e aceitou receber informações sobre revenda.
  <a href="<?= e($unsubUrl) ?>" style="color:#C3CADB;">Parar de receber</a>.
</p>
