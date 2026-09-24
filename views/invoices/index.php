<div class="toolbar">
  <form method="get" class="inline-form">
    <select name="status">
      <?php foreach (['open' => 'Em aberto', 'paid' => 'Pagas', 'cancelled' => 'Canceladas', 'all' => 'Todas'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <label class="inline-label">Vencimento de <input type="date" name="de" value="<?= e($from) ?>"></label>
    <label class="inline-label">até <input type="date" name="ate" value="<?= e($to) ?>"></label>
    <button class="btn">Filtrar</button>
  </form>
</div>
<div class="card">
<table class="table">
  <thead><tr><th>Cliente</th><th>Contrato</th><th>Título</th><th>Vencimento</th><th>Valor</th><th>Situação</th><th>Pago em</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i): ?>
    <tr>
      <td><a href="/clientes/<?= (int) $i['customer_id'] ?>"><?= e($i['customer_name']) ?></a></td>
      <td><?= e($i['contract_code'] ?? '—') ?></td>
      <td><?= e($i['sgp_id']) ?></td>
      <td><?= e(fmt_date($i['due_date'])) ?><?= $i['status'] === 'open' && $i['due_date'] < date('Y-m-d') ? ' <span class="badge badge-bad">atrasada</span>' : '' ?></td>
      <td>R$ <?= e(fmt_money($i['amount'])) ?></td>
      <td><?= status_badge($i['status']) ?></td>
      <td><?= e(fmt_date($i['paid_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted center">Nenhuma fatura.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php require BASE_PATH . '/views/partials/pager.php'; ?>
</div>
