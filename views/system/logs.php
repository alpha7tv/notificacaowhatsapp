<?php $cats = App\Core\Logger::CATEGORIES; ?>
<div class="tabs">
  <a href="?" class="<?= $cat === '' ? 'active' : '' ?>">Todos</a>
  <?php foreach ($cats as $k => $l): ?><a href="?categoria=<?= e($k) ?>&nivel=<?= e($level) ?>" class="<?= $cat === $k ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>
<div class="toolbar">
  <form method="get" class="inline-form">
    <input type="hidden" name="categoria" value="<?= e($cat) ?>">
    <select name="nivel">
      <option value="">Todos os níveis</option>
      <?php foreach (['info' => 'Info ou mais grave', 'warning' => 'Aviso ou mais grave', 'error' => 'Somente erros'] as $v => $l): ?><option value="<?= $v ?>" <?= $level === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar no texto">
    <button class="btn">Filtrar</button>
  </form>
</div>
<div class="card">
<table class="table compact logs">
  <thead><tr><th>Data</th><th>Categoria</th><th>Nível</th><th>Mensagem</th><th>Usuário</th></tr></thead>
  <?php foreach ($rows as $l): ?>
    <tr class="lvl-<?= e($l['level']) ?>">
      <td class="nowrap"><?= e(date('d/m H:i:s', strtotime($l['created_at']))) ?></td>
      <td><?= e($cats[$l['category']] ?? $l['category']) ?></td>
      <td><span class="badge badge-<?= ['error' => 'bad', 'critical' => 'bad', 'warning' => 'warn', 'info' => 'info'][$l['level']] ?? 'muted' ?>"><?= e($l['level']) ?></span></td>
      <td><?= e($l['message']) ?><?php if ($l['context']): ?><details><summary class="small muted">detalhes</summary><pre class="pre small"><?= e($l['context']) ?></pre></details><?php endif; ?></td>
      <td class="small"><?= e($l['user_name'] ?? '') ?> <span class="muted"><?= e($l['ip'] ?? '') ?></span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted center">Nenhum registro.</td></tr><?php endif; ?>
</table>
<?php require BASE_PATH . '/views/partials/pager.php'; ?>
<p class="muted small">Senhas, tokens, Authorization, APP_KEY, DB_PASSWORD e códigos PIX são mascarados antes de serem registrados.</p>
</div>
