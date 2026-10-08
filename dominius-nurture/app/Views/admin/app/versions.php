<?php /** @var array $rows @var int $nextCode */ include __DIR__ . '/_nav.php'; ?>
<div style="max-width:900px">
  <form method="post" action="/admin/meu-app/versoes" enctype="multipart/form-data" style="<?= $box ?>padding:22px;margin:0 0 18px">
    <?= csrf_field() ?>
    <h3 style="margin:0 0 12px">Publicar uma nova versão</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:0 0 14px">
      <div><label style="<?= $lbl ?>" for="v-code">Número da versão (sempre maior)</label><input class="input" style="<?= $fld ?>" id="v-code" name="version_code" type="number" min="1" required value="<?= (int) $nextCode ?>"></div>
      <div><label style="<?= $lbl ?>" for="v-name">Nome da versão (ex.: 1.0.0)</label><input class="input" style="<?= $fld ?>" id="v-name" name="version_name" required maxlength="20" placeholder="1.0.0"></div>
      <div><label style="<?= $lbl ?>" for="v-apk">Arquivo APK</label><input type="file" id="v-apk" name="apk" accept=".apk,application/vnd.android.package-archive" required></div>
    </div>
    <div style="margin:0 0 14px"><label style="<?= $lbl ?>" for="v-notes">O que mudou (aparece para o cliente)</label><textarea class="textarea" style="<?= $fld ?>" id="v-notes" name="notes" rows="3" maxlength="1000"></textarea></div>
    <label style="display:block;margin:0 0 16px"><input type="checkbox" name="mandatory" value="1"> Atualização obrigatória (o app só abre depois de atualizar)</label>
    <button class="btn btn-primary" type="submit">Publicar</button>
  </form>
  <div style="<?= $box ?>overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:640px">
      <thead><tr><th style="<?= $th ?>">Versão</th><th style="<?= $th ?>">Tamanho</th><th style="<?= $th ?>">Publicada em</th><th style="<?= $th ?>">Downloads</th><th style="<?= $th ?>"></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted" style="<?= $td ?>text-align:center;padding:26px">Nenhuma versão publicada.</td></tr><?php endif; ?>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td style="<?= $td ?>"><strong><?= e($r['version_name']) ?></strong> <span class="muted">(nº <?= (int) $r['version_code'] ?>)</span><?= $i === 0 ? ' <span style="color:#25D366">· atual</span>' : '' ?><?= $r['mandatory'] ? ' <span style="color:#FFC94D">· obrigatória</span>' : '' ?><br><span class="muted" style="font-size:.82rem"><?= e(mb_substr((string) $r['notes'], 0, 90)) ?></span></td>
          <td style="<?= $td ?>white-space:nowrap"><?= e(number_format($r['size_bytes'] / 1048576, 1, ',', '.')) ?> MB</td>
          <td style="<?= $td ?>white-space:nowrap"><?= e(dt($r['created_at'])) ?></td>
          <td style="<?= $td ?>"><?= (int) $r['downloads'] ?></td>
          <td style="<?= $td ?>"><form method="post" action="/admin/meu-app/versoes/<?= (int) $r['id'] ?>/excluir" onsubmit="return confirm('Excluir esta versão e o arquivo?');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>color:#FF4D6D;border-color:rgba(255,77,109,.5)">Excluir</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin:12px 0 0;font-size:.85rem">O link <code>/app</code> sempre baixa a versão mais recente. Para a TV, digite no Downloader o endereço <code>dominiusplay.top/app</code>.</p>
</div>
