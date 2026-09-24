<div class="card">
<table class="table compact">
  <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Objeto</th><th>Detalhes</th><th>IP</th></tr></thead>
  <?php foreach ($rows as $a): ?>
    <tr><td class="nowrap"><?= e(fmt_datetime($a['created_at'])) ?></td><td><?= e($a['user_name'] ?? 'sistema') ?></td><td class="mono small"><?= e($a['action']) ?></td>
      <td class="small"><?= e(trim($a['entity'] . ' ' . $a['entity_id'])) ?></td><td class="small mono"><?= e(mb_strimwidth((string) $a['details'], 0, 160, '…')) ?></td><td class="small"><?= e($a['ip'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted center">Nenhum registro.</td></tr><?php endif; ?>
</table>
</div>
