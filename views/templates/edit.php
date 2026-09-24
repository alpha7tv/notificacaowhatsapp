<div class="toolbar"><a class="btn btn-ghost" href="/templates">‹ Modelos</a></div>
<div class="grid-2">
  <div class="card">
    <form method="post" action="/templates/salvar" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <label>Nome<input name="name" value="<?= e($t['name']) ?>" required maxlength="120"></label>
      <label>Texto da mensagem
        <textarea name="body" rows="16" required maxlength="4000" class="mono"><?= e($t['body']) ?></textarea>
      </label>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> Modelo ativo</label>
      <?php if ($unknown): ?><div class="alert alert-error">Variáveis inexistentes: <?= e(implode(', ', $unknown)) ?></div><?php endif; ?>
      <?php if (App\Core\Auth::isAdmin()): ?><button class="btn btn-primary">Salvar</button><?php endif; ?>
    </form>
  </div>
  <div>
    <div class="card">
      <h2>Pré-visualização (dados de exemplo)</h2>
      <?php if ($preview !== ''): ?><div class="wa-bubble"><?= nl2br(e($preview)) ?></div><?php else: ?><p class="muted">Salve para visualizar.</p><?php endif; ?>
    </div>
    <div class="card">
      <h2>Variáveis disponíveis</h2>
      <p class="muted small">Use <code>{{variavel}}</code>. Para mostrar um trecho só quando houver valor: <code>{{#pix}}PIX: {{pix}}{{/pix}}</code>. Negrito do WhatsApp: <code>*texto*</code>.</p>
      <table class="kv small">
        <?php foreach ($variables as $k => $desc): ?><tr><th class="mono">{{<?= e($k) ?>}}</th><td><?= e($desc) ?></td></tr><?php endforeach; ?>
      </table>
    </div>
  </div>
</div>
