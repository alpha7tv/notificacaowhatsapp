<div class="toolbar">
  <form method="get" class="inline-form">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, CPF/CNPJ ou telefone">
    <button class="btn">Buscar</button>
  </form>
  <details class="dropdown">
    <summary class="btn btn-primary">+ Adicionar cliente</summary>
    <div class="dropdown-body card">
      <form method="post" action="/clientes/adicionar" class="form">
        <?= csrf_field() ?>
        <label>CPF/CNPJ *<input name="document" required inputmode="numeric"></label>
        <label>Nome<input name="name"></label>
        <label>Celular (se não vier do SGP)<input name="phone" inputmode="tel"></label>
        <button class="btn btn-primary">Cadastrar<?= $sgpConfigured ? ' e sincronizar' : '' ?></button>
      </form>
      <hr>
      <form method="post" action="/clientes/importar" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <label>Importar CSV (documento;nome;telefone)<input type="file" name="csv" accept=".csv,text/csv" required></label>
        <button class="btn">Importar</button>
      </form>
    </div>
  </details>
</div>
<?php if (!$sgpConfigured): ?>
<div class="alert alert-warning">O SGP ainda não foi configurado. <a href="/integracoes">Configurar integração</a></div>
<?php endif; ?>
<div class="card">
<table class="table">
  <thead><tr><th>Nome</th><th>CPF/CNPJ</th><th>Celular</th><th>Contratos</th><th>Faturas abertas</th><th>Sincronizado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><a href="/clientes/<?= (int) $c['id'] ?>"><?= e($c['name'] ?: '(sem nome)') ?></a><?= $c['opt_out'] ? ' <span class="badge badge-muted">bloqueado</span>' : '' ?></td>
      <td><?= e(mask_document($c['document'])) ?></td>
      <td><?= $c['phone'] ? e(mask_phone($c['phone'])) : '<span class="badge badge-bad">sem celular</span>' ?></td>
      <td><?php foreach (array_filter(explode(',', (string) $c['contract_status'])) as $st) { echo status_badge($st) . ' '; } ?></td>
      <td><?= (int) $c['open_invoices'] ?></td>
      <td class="muted"><?= e(ago($c['last_synced_at'])) ?><?= $c['sync_error'] ? ' <span class="badge badge-bad" title="' . e($c['sync_error']) . '">erro</span>' : '' ?></td>
      <td><a class="btn btn-sm" href="/clientes/<?= (int) $c['id'] ?>">Abrir</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted center">Nenhum cliente encontrado.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php require BASE_PATH . '/views/partials/pager.php'; ?>
</div>
