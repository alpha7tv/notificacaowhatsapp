<div class="toolbar"><a class="btn btn-ghost" href="/sistema/atualizacoes">‹ Atualizações</a></div>
<div class="card">
  <table class="kv">
    <tr><th>Tarefa</th><td><?= e(App\Services\SystemJobs::TYPES[$job['type']] ?? $job['type']) ?></td></tr>
    <tr><th>Situação</th><td><?= status_badge($job['status']) ?></td></tr>
    <tr><th>Solicitada por</th><td><?= e($job['user_name'] ?? '—') ?> (<?= e($job['requested_ip'] ?? '') ?>) em <?= e(fmt_datetime($job['created_at'])) ?></td></tr>
    <tr><th>Início / fim</th><td><?= e(fmt_datetime($job['started_at'])) ?> — <?= e(fmt_datetime($job['finished_at'])) ?></td></tr>
    <tr><th>Resultado</th><td><?= e($job['result'] ?? '') ?></td></tr>
  </table>
  <h3>Log</h3>
  <pre class="pre"><?= e($log !== '' ? $log : 'Sem log ainda.') ?></pre>
  <?php if (in_array($job['status'], ['queued', 'running'], true)): ?><p class="muted small" data-autorefresh="5">Atualizando automaticamente…</p><?php endif; ?>
</div>
