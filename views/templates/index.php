<div class="toolbar"><div class="spacer"></div><?php if (App\Core\Auth::isAdmin()): ?><a class="btn btn-primary" href="/templates/novo">+ Novo modelo</a><?php endif; ?></div>
<div class="card">
<table class="table">
  <thead><tr><th>Modelo</th><th>Regras ativas usando</th><th>Ativo</th><th>Atualizado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $t): ?>
    <tr>
      <td><a href="/templates/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></td>
      <td><?= (int) $t['rules_count'] ?></td>
      <td><?= $t['active'] ? '<span class="badge badge-ok">Sim</span>' : '<span class="badge badge-muted">Não</span>' ?></td>
      <td class="muted"><?= e(fmt_datetime($t['updated_at'])) ?></td>
      <td><a class="btn btn-sm" href="/templates/<?= (int) $t['id'] ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
