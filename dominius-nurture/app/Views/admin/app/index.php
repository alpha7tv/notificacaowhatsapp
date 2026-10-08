<?php /** @var array $stats @var ?array $version */ include __DIR__ . '/_nav.php'; ?>
<div style="max-width:980px">
  <p class="muted" style="margin:0 0 16px">Gerencie quem usa o aplicativo Dominius Play, o que aparece para o cliente e as versões publicadas.</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px">
    <?php foreach (['active' => ['Acessos ativos', '#25D366'], 'trial' => ['Testes ativos', '#FFC94D'], 'expired' => ['Vencidos', '#FF4D6D'], 'blocked' => ['Bloqueados', '#8E98B0'], 'devices' => ['Aparelhos', '#7CC4FF'], 'notices' => ['Avisos no ar', '#FF9A4D']] as $k => [$lb, $c]): ?>
      <div style="<?= $box ?>padding:14px 18px;min-width:140px"><div style="font-size:1.6rem;font-weight:800;color:<?= e($c) ?>"><?= (int) $stats[$k] ?></div><div class="muted" style="font-size:.85rem"><?= e($lb) ?></div></div>
    <?php endforeach; ?>
  </div>
  <div style="<?= $box ?>padding:18px 22px">
    <h3 style="margin:0 0 8px">Versão publicada</h3>
    <?php if ($version): ?>
      <p style="margin:0">Versão <strong><?= e($version['version_name']) ?></strong> (nº <?= (int) $version['version_code'] ?>) · <?= (int) $version['downloads'] ?> download(s) · link do cliente: <a href="/app" target="_blank" rel="noopener">/app</a></p>
    <?php else: ?>
      <p class="muted" style="margin:0">Nenhum APK publicado ainda. Envie o primeiro em <a href="/admin/meu-app/versoes">Versões do app</a>. Enquanto não houver APK, os e-mails de teste não mencionam o aplicativo.</p>
    <?php endif; ?>
  </div>
</div>
