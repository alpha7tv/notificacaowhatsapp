<div class="toolbar">
  <a class="btn btn-ghost" href="/mensagens">‹ Mensagens</a>
  <div class="spacer"></div>
  <?php if (in_array($m['status'], ['failed', 'cancelled', 'skipped'], true)): ?>
    <form method="post" action="/mensagens/<?= (int) $m['id'] ?>/reenviar" class="inline" data-confirm="Devolver esta mensagem à fila de envio?"><?= csrf_field() ?><button class="btn btn-primary">Reenviar</button></form>
  <?php endif; ?>
  <?php if ($m['status'] === 'pending'): ?>
    <form method="post" action="/mensagens/<?= (int) $m['id'] ?>/cancelar" class="inline" data-confirm="Cancelar esta mensagem?"><?= csrf_field() ?><button class="btn btn-danger">Cancelar</button></form>
  <?php endif; ?>
</div>
<div class="grid-2">
  <div class="card">
    <table class="kv">
      <tr><th>Situação</th><td><?= status_badge($m['status']) ?> <?= e($m['status_reason'] ?? '') ?></td></tr>
      <tr><th>Evento</th><td><?= e(event_label($m['event'])) ?><?= $m['rule_name'] ? ' — ' . e($m['rule_name']) : '' ?></td></tr>
      <tr><th>Cliente</th><td><?= $m['customer_id'] ? '<a href="/clientes/' . (int) $m['customer_id'] . '">' . e($m['customer_name']) . '</a>' : '—' ?></td></tr>
      <tr><th>Destino</th><td><?= e(mask_phone($m['destination'])) ?></td></tr>
      <tr><th>Enviada para</th><td><?= e(mask_phone($m['sent_to'])) ?><?= $m['homologation'] ? ' <span class="badge badge-warn">número de teste (homologação)</span>' : '' ?></td></tr>
      <tr><th>Previsto para</th><td><?= e(fmt_datetime($m['available_at'])) ?></td></tr>
      <tr><th>Enviada em</th><td><?= e(fmt_datetime($m['sent_at'])) ?></td></tr>
      <tr><th>Entregue em</th><td><?= e(fmt_datetime($m['delivered_at'])) ?></td></tr>
      <tr><th>Tentativas</th><td><?= (int) $m['attempts'] ?> de <?= (int) $m['max_attempts'] ?></td></tr>
      <tr><th>Último erro</th><td class="text-bad"><?= e($m['last_error'] ?? '—') ?></td></tr>
      <tr><th>Chave de idempotência</th><td class="mono small"><?= e($m['idempotency_key']) ?></td></tr>
      <tr><th>Criada em</th><td><?= e(fmt_datetime($m['created_at'])) ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Texto</h2>
    <div class="wa-bubble"><?= nl2br(e(App\Core\Masker::string($m['body']))) ?></div>
    <p class="muted small">Códigos PIX e dados sensíveis aparecem mascarados nesta tela.</p>
  </div>
</div>
