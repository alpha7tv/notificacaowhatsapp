<div class="toolbar">
  <a class="btn btn-ghost" href="/clientes">‹ Clientes</a>
  <div class="spacer"></div>
  <form method="post" action="/clientes/<?= (int) $c['id'] ?>/sincronizar" class="inline"><?= csrf_field() ?><button class="btn">Sincronizar com SGP</button></form>
  <form method="post" action="/clientes/<?= (int) $c['id'] ?>/optout" class="inline" data-confirm="<?= $c['opt_out'] ? 'Liberar envios para este cliente?' : 'Bloquear TODOS os envios para este cliente?' ?>"><?= csrf_field() ?>
    <button class="btn <?= $c['opt_out'] ? '' : 'btn-danger' ?>"><?= $c['opt_out'] ? 'Liberar envios' : 'Bloquear envios' ?></button></form>
</div>
<div class="grid-2">
  <div class="card">
    <h2>Dados</h2>
    <table class="kv">
      <tr><th>Nome</th><td><?= e($c['name']) ?></td></tr>
      <tr><th>CPF/CNPJ</th><td><?= e(mask_document($c['document'])) ?></td></tr>
      <tr><th>Celular usado</th><td><?= e(mask_phone($c['phone'])) ?> <?= $c['wa_exists'] === null ? '' : ((int) $c['wa_exists'] ? '<span class="badge badge-ok">WhatsApp OK</span>' : '<span class="badge badge-bad">sem WhatsApp</span>') ?></td></tr>
      <tr><th>Telefones no SGP</th><td class="muted"><?= e(implode(' / ', array_map('mask_phone', array_filter(explode(' / ', (string) $c['phone_raw']))))) ?: '—' ?></td></tr>
      <tr><th>Envios</th><td><?= $c['opt_out'] ? '<span class="badge badge-bad">Bloqueados</span>' : '<span class="badge badge-ok">Liberados</span>' ?></td></tr>
      <tr><th>Última sincronização</th><td><?= e(fmt_datetime($c['last_synced_at'])) ?> <?= $c['sync_error'] ? '<span class="badge badge-bad">' . e($c['sync_error']) . '</span>' : '' ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Contratos</h2>
    <table class="table compact">
      <thead><tr><th>Contrato</th><th>Plano</th><th>Situação</th><th>Desde</th></tr></thead>
      <?php foreach ($contracts as $k): ?>
        <tr><td><?= e($k['sgp_id']) ?></td><td><?= e($k['plan'] ?? '—') ?></td><td><?= status_badge($k['status']) ?> <span class="muted"><?= e($k['status_raw'] ?? '') ?></span></td><td><?= e(fmt_date($k['status_changed_at'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?><tr><td colspan="4" class="muted">Nenhum contrato sincronizado.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<div class="card">
  <h2>Faturas</h2>
  <table class="table compact">
    <thead><tr><th>Título</th><th>Vencimento</th><th>Valor</th><th>Situação</th><th>Pago em</th><th>PIX</th><th>Linha digitável</th></tr></thead>
    <?php foreach ($invoices as $i): ?>
      <tr><td><?= e($i['sgp_id']) ?></td><td><?= e(fmt_date($i['due_date'])) ?></td><td>R$ <?= e(fmt_money($i['amount'])) ?></td><td><?= status_badge($i['status']) ?></td>
        <td><?= e(fmt_date($i['paid_at'])) ?></td><td><?= $i['pix_code'] ? 'sim' : '—' ?></td><td><?= $i['barcode'] ? 'sim' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7" class="muted">Nenhuma fatura.</td></tr><?php endif; ?>
  </table>
</div>
<div class="card">
  <h2>Mensagens</h2>
  <table class="table compact">
    <thead><tr><th>#</th><th>Evento</th><th>Situação</th><th>Previsto</th><th>Enviada</th></tr></thead>
    <?php foreach ($messages as $m): ?>
      <tr><td><a href="/mensagens/<?= (int) $m['id'] ?>">#<?= (int) $m['id'] ?></a></td><td><?= e(event_label($m['event'])) ?></td><td><?= status_badge($m['status']) ?></td>
        <td><?= e(fmt_datetime($m['available_at'])) ?></td><td><?= e(fmt_datetime($m['sent_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$messages): ?><tr><td colspan="5" class="muted">Nenhuma mensagem.</td></tr><?php endif; ?>
  </table>
</div>
