<?php
/** Estilos e menu comuns das páginas do painel do aplicativo. */
$box = 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);border-radius:14px;';
$th = 'text-align:left;padding:10px 12px;font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;opacity:.7;white-space:nowrap;border-bottom:1px solid rgba(255,255,255,.12);';
$td = 'padding:12px;vertical-align:top;border-bottom:1px solid rgba(255,255,255,.07);font-size:.92rem;';
$mini = 'padding:6px 10px;font-size:.78rem;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:transparent;color:inherit;cursor:pointer;text-decoration:none;display:inline-block;';
$fld = 'display:block;width:100%;box-sizing:border-box;';
$lbl = 'display:block;margin:0 0 6px;font-size:.88rem;opacity:.85;';
$tabs = ['/admin/meu-app' => 'Visão geral', '/admin/meu-app/clientes' => 'Clientes', '/admin/meu-app/avisos' => 'Avisos e publicidade', '/admin/meu-app/configuracoes' => 'Configurações', '/admin/meu-app/versoes' => 'Versões do app'];
$here = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
?>
<nav style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px" aria-label="Aplicativo">
  <?php foreach ($tabs as $href => $label):
      $active = $href === '/admin/meu-app' ? $here === '/admin/meu-app' : str_starts_with((string) $here, $href); ?>
    <a href="<?= e($href) ?>" style="padding:8px 14px;border-radius:999px;text-decoration:none;font-size:.9rem;border:1px solid rgba(255,255,255,<?= $active ? '.5' : '.15' ?>);background:<?= $active ? 'rgba(255,201,77,.15)' : 'transparent' ?>;color:inherit"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
