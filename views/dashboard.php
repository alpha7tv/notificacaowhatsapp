<?php
$integLabel = static fn (string $s) => ['ok' => ['OK', 'ok'], 'fail' => ['FALHA', 'bad'], 'not_configured' => ['NÃO CONFIGURADO', 'muted']][$s] ?? [$s, 'muted'];
$hb = static function (?array $h, int $max): string {
    if (!$h) {
        return '<span class="badge badge-bad">Nunca executou</span>';
    }
    $ok = (int) $h['age'] <= $max;
    return '<span class="badge badge-' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'PARADO') . '</span> <span class="muted">' . e(ago($h['beat_at'])) . '</span>';
};
?>
<div class="stats">
  <a class="stat" href="/clientes"><b><?= $stats['customers'] ?? 0 ?></b><span>Clientes</span></a>
  <a class="stat" href="/faturas?status=open"><b><?= $stats['open_invoices'] ?? 0 ?></b><span>Faturas em aberto</span></a>
  <a class="stat" href="/faturas?status=open&de=<?= date('Y-m-d') ?>&ate=<?= date('Y-m-d') ?>"><b><?= $stats['due_today'] ?? 0 ?></b><span>Vencem hoje</span></a>
  <a class="stat warn" href="/faturas?status=open&ate=<?= date('Y-m-d', strtotime('-1 day')) ?>"><b><?= $stats['overdue'] ?? 0 ?></b><span>Em atraso</span></a>
  <a class="stat" href="/mensagens?status=pending"><b><?= $stats['pending'] ?? 0 ?></b><span>Na fila</span></a>
  <a class="stat ok" href="/mensagens?status=sent"><b><?= $stats['sent_today'] ?? 0 ?></b><span>Enviadas hoje</span></a>
  <a class="stat bad" href="/mensagens?status=failed"><b><?= $stats['failed_24h'] ?? 0 ?></b><span>Falhas (24h)</span></a>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Serviços</h2>
    <table class="kv">
      <tr><th>Worker (fila WhatsApp)</th><td><?= $hb($worker, 120) ?></td></tr>
      <tr><th>Scheduler (rotinas)</th><td><?= $hb($scheduler, 180) ?></td></tr>
      <?php [$l, $c] = $integLabel($integrations['sgp']); ?>
      <tr><th>SGP</th><td><span class="badge badge-<?= $c ?>"><?= $l ?></span> <span class="muted"><?= e($integrations['sgp_detail']) ?></span></td></tr>
      <?php [$l, $c] = $integLabel($integrations['whatsapp']); ?>
      <tr><th>WhatsApp</th><td><span class="badge badge-<?= $c ?>"><?= $l ?></span> <span class="muted"><?= e($integrations['whatsapp_detail']) ?></span></td></tr>
      <tr><th>Última sincronização</th><td><?= $lastSync ? status_badge($lastSync['status']) . ' ' . e(fmt_datetime($lastSync['started_at'])) . ' — ' . e($lastSync['message'] ?? '') : '<span class="muted">nunca</span>' ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Últimas mensagens</h2>
    <?php if (!$recent): ?><p class="muted">Nenhuma mensagem ainda.</p><?php else: ?>
    <table class="table compact">
      <?php foreach ($recent as $m): ?>
        <tr>
          <td><a href="/mensagens/<?= (int) $m['id'] ?>">#<?= (int) $m['id'] ?></a></td>
          <td><?= e($m['customer_name'] ?? ($m['is_test'] ? 'Teste' : '—')) ?></td>
          <td><?= e(event_label($m['event'])) ?></td>
          <td><?= status_badge($m['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
