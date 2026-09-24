<?php
$h = $st['health']['checks'] ?? [];
$badge = static function (?string $s, string $okLabel = 'OK'): string {
    return match ($s) {
        'ok' => '<span class="badge badge-ok">' . e($okLabel) . '</span>',
        'not_configured' => '<span class="badge badge-muted">NÃO CONFIGURADO</span>',
        'warn' => '<span class="badge badge-warn">ATENÇÃO</span>',
        null, '' => '<span class="badge badge-muted">sem dados</span>',
        default => '<span class="badge badge-bad">FALHA</span>',
    };
};
$hb = static fn (?array $x, int $max) => $x ? ((int) $x['age'] <= $max ? 'ok' : 'fail') : 'fail';
$mem = $st['memory'];
$disk = $st['disk'];
?>
<div class="grid-2">
  <div class="card">
    <h2>Servidor</h2>
    <table class="kv">
      <tr><th>Hostname</th><td><?= e($st['hostname']) ?></td></tr>
      <tr><th>Sistema</th><td><?= e($st['os']) ?></td></tr>
      <tr><th>Uptime</th><td><?= $st['uptime'] !== null ? e(fmt_duration((int) $st['uptime'])) : '—' ?></td></tr>
      <tr><th>CPU</th><td><?= e(($st['cpus'] ?? '?') . ' núcleo(s)') ?> <span class="muted small"><?= e($st['cpu_model'] ?? '') ?></span></td></tr>
      <tr><th>Carga (1/5/15 min)</th><td><?= $st['load'] ? e(implode(' / ', array_map(static fn ($l) => number_format((float) $l, 2, ',', ''), $st['load']))) : '—' ?></td></tr>
      <tr><th>RAM</th><td><?php if ($mem): $used = $mem['total'] - $mem['available']; $pct = (int) round($used / max(1, $mem['total']) * 100); ?>
        <?= e(fmt_bytes($used)) ?> de <?= e(fmt_bytes($mem['total'])) ?> (<?= $pct ?>%)<progress class="bar<?= $pct > 90 ? ' bad' : '' ?>" value="<?= $pct ?>" max="100"></progress><?php else: ?>—<?php endif; ?></td></tr>
      <tr><th>Disco</th><td><?php if ($disk['total'] > 0): $used = $disk['total'] - $disk['free']; $pct = (int) round($used / $disk['total'] * 100); ?>
        <?= e(fmt_bytes($used)) ?> de <?= e(fmt_bytes($disk['total'])) ?> (<?= $pct ?>%)<progress class="bar<?= $pct > 90 ? ' bad' : '' ?>" value="<?= $pct ?>" max="100"></progress><?php else: ?>—<?php endif; ?></td></tr>
      <tr><th>PHP</th><td><?= e($st['php']) ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Aplicação</h2>
    <table class="kv">
      <tr><th>Versão</th><td><b><?= e($st['app_version']) ?></b> <span class="muted small"><?= e($st['release']) ?></span></td></tr>
      <tr><th>Banco de dados</th><td><?= $badge($st['db_version'] ? 'ok' : 'fail') ?> <span class="muted"><?= e($st['db_version'] ?? '') ?></span></td></tr>
      <tr><th>Redis</th><td><?= $badge($st['redis']) ?> <span class="muted"><?= e($st['redis_version'] ?? '') ?></span></td></tr>
      <tr><th>Worker</th><td><?= $badge($hb($st['worker'], 120)) ?> <span class="muted"><?= $st['worker'] ? e(ago($st['worker']['beat_at']) . ' — ' . $st['worker']['info']) : 'nunca executou' ?></span></td></tr>
      <tr><th>Scheduler</th><td><?= $badge($hb($st['scheduler'], 180)) ?> <span class="muted"><?= $st['scheduler'] ? e(ago($st['scheduler']['beat_at'])) : 'nunca executou' ?></span></td></tr>
      <tr><th>Nginx</th><td><?= $badge($h['nginx']['status'] ?? null) ?></td></tr>
      <tr><th>SSL painel</th><td><?= $badge($h['ssl_painel']['status'] ?? null) ?> <span class="muted"><?= e($h['ssl_painel']['detail'] ?? '') ?></span></td></tr>
      <tr><th>SSL API</th><td><?= $badge($h['ssl_api']['status'] ?? null) ?> <span class="muted"><?= e($h['ssl_api']['detail'] ?? '') ?></span></td></tr>
      <tr><th>Último backup</th><td><?= $st['backup'] ? $badge($st['backup']['status'] ?? null) . ' ' . e(fmt_datetime($st['backup']['finished_at'] ?? null)) . ' <span class="muted">' . e(fmt_bytes((float) ($st['backup']['size'] ?? 0))) . '</span>' : '<span class="muted">nenhum</span>' ?></td></tr>
      <tr><th>Último deploy</th><td><?= $lastDeploy ? status_badge($lastDeploy['status']) . ' ' . e(fmt_datetime($lastDeploy['created_at']) . ' — v' . $lastDeploy['version'] . ' (' . $lastDeploy['kind'] . ')') : '<span class="muted">—</span>' ?></td></tr>
      <tr><th>Health check do servidor</th><td class="muted"><?= $st['health'] ? e(fmt_datetime($st['health']['generated_at'] ?? null)) : 'ainda não executado' ?></td></tr>
    </table>
    <h3>Integrações</h3>
    <table class="kv">
      <tr><th>SGP</th><td><?= $badge($integrations['sgp']) ?> <span class="muted"><?= e($integrations['sgp_detail']) ?></span></td></tr>
      <tr><th>WhatsApp</th><td><?= $badge($integrations['whatsapp']) ?> <span class="muted"><?= e($integrations['whatsapp_detail']) ?></span></td></tr>
    </table>
  </div>
</div>
<div class="card">
  <h2>Rotinas programadas (scheduler)</h2>
  <table class="table compact">
    <thead><tr><th>Rotina</th><th>Intervalo</th><th>Última execução</th><th>Situação</th><th>Duração</th><th>Resultado</th><th>Próxima</th><th></th></tr></thead>
    <?php foreach ($tasks as $t): ?>
      <tr>
        <td><b><?= e($t['name']) ?></b><br><span class="muted small"><?= e($t['description']) ?></span></td>
        <td><?= e(fmt_duration((int) $t['interval_seconds'])) ?></td>
        <td><?= e(fmt_datetime($t['last_started_at'])) ?></td>
        <td><?= $t['last_status'] ? status_badge($t['last_status'] === 'skipped' ? 'skipped_task' : $t['last_status']) : '—' ?></td>
        <td><?= $t['last_duration_ms'] !== null ? (int) $t['last_duration_ms'] . ' ms' : '—' ?></td>
        <td class="small"><?= e($t['last_message'] ?? '') ?></td>
        <td><?= e(fmt_datetime($t['next_run_at'])) ?></td>
        <td><form method="post" action="/sistema/rotinas/executar" class="inline"><?= csrf_field() ?><input type="hidden" name="task" value="<?= e($t['name']) ?>"><button class="btn btn-sm">Executar agora</button></form></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
