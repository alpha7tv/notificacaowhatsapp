<div class="tabs">
  <a href="?" class="<?= $source === '' ? 'active' : '' ?>">Todos</a>
  <a href="?origem=sgp" class="<?= $source === 'sgp' ? 'active' : '' ?>">SGP</a>
  <a href="?origem=whatsapp" class="<?= $source === 'whatsapp' ? 'active' : '' ?>">WhatsApp</a>
</div>
<div class="card">
<table class="table compact">
  <thead><tr><th>Data</th><th>Origem</th><th>Evento</th><th>Situação</th><th>HTTP</th><th>IP</th><th>Detalhes</th></tr></thead>
  <?php foreach ($rows as $w): ?>
    <tr>
      <td class="nowrap"><?= e(fmt_datetime($w['created_at'])) ?></td>
      <td><?= e(strtoupper($w['source'])) ?></td>
      <td><?= e($w['event_type'] ?? '—') ?></td>
      <td><?= status_badge($w['status']) ?></td>
      <td><?= (int) $w['http_status'] ?></td>
      <td class="small"><?= e($w['ip'] ?? '') ?></td>
      <td><?= $w['error'] ? '<span class="small">' . e($w['error']) . '</span>' : '' ?><?php if ($w['payload']): ?><details><summary class="small muted">payload (mascarado)</summary><pre class="pre small"><?= e($w['payload']) ?></pre></details><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted center">Nenhum webhook recebido.</td></tr><?php endif; ?>
</table>
</div>
