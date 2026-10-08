<?php /** @var ?array $n */ include __DIR__ . '/_nav.php';
$c = $n ?? ['kind' => 'info', 'placement' => 'home', 'audience' => 'all', 'is_active' => 1, 'sort_order' => 0];
$action = $n ? '/admin/meu-app/avisos/' . (int) $n['id'] : '/admin/meu-app/avisos';
$starts = \App\Controllers\Admin\AppAdminController::utcToLocal($c['starts_at'] ?? null);
$ends = \App\Controllers\Admin\AppAdminController::utcToLocal($c['ends_at'] ?? null);
?>
<div style="max-width:760px">
  <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" style="<?= $box ?>padding:22px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:0 0 14px">
      <div><label style="<?= $lbl ?>" for="n-kind">Tipo</label>
        <select class="select" style="<?= $fld ?>" id="n-kind" name="kind"><?php foreach (\App\Services\AppService::NOTICE_KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label style="<?= $lbl ?>" for="n-place">Onde aparece</label>
        <select class="select" style="<?= $fld ?>" id="n-place" name="placement"><?php foreach (\App\Services\AppService::PLACEMENTS as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['placement'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label style="<?= $lbl ?>" for="n-aud">Para quem</label>
        <select class="select" style="<?= $fld ?>" id="n-aud" name="audience"><?php foreach (\App\Services\AppService::AUDIENCES as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['audience'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div style="margin:0 0 14px"><label style="<?= $lbl ?>" for="n-title">Título</label><input class="input" style="<?= $fld ?>" id="n-title" name="title" required maxlength="150" value="<?= e($c['title'] ?? '') ?>"></div>
    <div style="margin:0 0 14px"><label style="<?= $lbl ?>" for="n-body">Texto</label><textarea class="textarea" style="<?= $fld ?>" id="n-body" name="body" rows="4" maxlength="2000"><?= e($c['body'] ?? '') ?></textarea></div>
    <div style="margin:0 0 14px">
      <label style="<?= $lbl ?>" for="n-img">Imagem (opcional, JPG/PNG/WEBP até 2 MB; recomendado 1280×400)</label>
      <?php if (!empty($c['image_path'])): ?>
        <div style="margin:0 0 8px"><img src="/uploads/app/<?= e(rawurlencode(basename((string) $c['image_path']))) ?>" alt="" style="max-width:320px;border-radius:10px"><br>
        <label><input type="checkbox" name="remove_image" value="1"> remover a imagem atual</label></div>
      <?php endif; ?>
      <input type="file" id="n-img" name="image" accept="image/jpeg,image/png,image/webp">
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:0 0 14px">
      <div><label style="<?= $lbl ?>" for="n-link">Link (opcional)</label><input class="input" style="<?= $fld ?>" id="n-link" name="link_url" maxlength="255" placeholder="https://..." value="<?= e($c['link_url'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="n-linklabel">Texto do botão do link</label><input class="input" style="<?= $fld ?>" id="n-linklabel" name="link_label" maxlength="40" placeholder="Saiba mais" value="<?= e($c['link_label'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="n-start">Começa em (opcional)</label><input class="input" style="<?= $fld ?>" id="n-start" name="starts" type="datetime-local" value="<?= e($starts) ?>"></div>
      <div><label style="<?= $lbl ?>" for="n-end">Termina em (opcional)</label><input class="input" style="<?= $fld ?>" id="n-end" name="ends" type="datetime-local" value="<?= e($ends) ?>"></div>
      <div><label style="<?= $lbl ?>" for="n-ord">Ordem (menor aparece primeiro)</label><input class="input" style="<?= $fld ?>" id="n-ord" name="sort_order" type="number" value="<?= (int) $c['sort_order'] ?>"></div>
    </div>
    <label style="display:block;margin:0 0 16px"><input type="checkbox" name="is_active" value="1"<?= !empty($c['is_active']) ? ' checked' : '' ?>> Ativo (aparece no app)</label>
    <button class="btn btn-primary" type="submit"><?= $n ? 'Salvar' : 'Criar' ?></button>
    <a class="btn" href="/admin/meu-app/avisos">Voltar</a>
  </form>
</div>
