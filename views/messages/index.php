<div class="toolbar">
  <form method="get" class="inline-form">
    <select name="status">
      <option value="">Todas as situações</option>
      <?php foreach (['pending' => 'Pendentes', 'processing' => 'Enviando', 'sent' => 'Enviadas', 'delivered' => 'Entregues', 'read' => 'Lidas', 'failed' => 'Com falha', 'cancelled' => 'Canceladas', 'skipped' => 'Ignoradas'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="evento">
      <option value="">Todos os eventos</option>
      <?php foreach (['before_due', 'due_today', 'after_due', 'payment_confirmed', 'suspended', 'cancelled', 'reactivated', 'test'] as $ev): ?>
        <option value="<?= $ev ?>" <?= $event === $ev ? 'selected' : '' ?>><?= e(event_label($ev)) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filtrar</button>
  </form>
</div>
<div class="card">
<table class="table">
  <thead><tr><th>#</th><th>Cliente</th><th>Evento</th><th>Destino</th><th>Situação</th><th>Previsto para</th><th>Enviada</th><th>Tentativas</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
    <tr>
      <td><a href="/mensagens/<?= (int) $m['id'] ?>">#<?= (int) $m['id'] ?></a></td>
      <td><?= e($m['customer_name'] ?? ($m['is_test'] ? 'Teste' : '—')) ?></td>
      <td><?= e(event_label($m['event'])) ?></td>
      <td><?= e(mask_phone($m['destination'])) ?><?= $m['homologation'] ? ' <span class="badge badge-warn" title="Enviada ao número de teste">homolog.</span>' : '' ?></td>
      <td><?= status_badge($m['status']) ?></td>
      <td><?= e(fmt_datetime($m['available_at'])) ?></td>
      <td><?= e(fmt_datetime($m['sent_at'])) ?></td>
      <td><?= (int) $m['attempts'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted center">Nenhuma mensagem.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php require BASE_PATH . '/views/partials/pager.php'; ?>
</div>
