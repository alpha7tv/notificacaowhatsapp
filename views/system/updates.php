<?php
$upd = $st['update'] ?? null;
$available = (bool) ($upd['update_available'] ?? false);
$remote = (bool) ($upd['remote_configured'] ?? false);
$running = false;
foreach ($jobs as $j) {
    if (in_array($j['status'], ['queued', 'running'], true)) {
        $running = true;
    }
}
?>
<div class="grid-2">
  <div class="card">
    <h2>Versão</h2>
    <table class="kv">
      <tr><th>Versão instalada</th><td><b><?= e($st['app_version']) ?></b> <span class="muted small"><?= e($st['release']) ?></span></td></tr>
      <tr><th>Versão disponível</th><td><?php if (!$upd): ?><span class="muted">Clique em "Verificar atualização"</span>
        <?php elseif (!$remote): ?><span class="badge badge-muted">Repositório remoto não configurado</span>
        <?php else: ?><b><?= e($upd['available_version'] ?? '?') ?></b> <?= $available ? '<span class="badge badge-warn">atualização disponível</span>' : '<span class="badge badge-ok">em dia</span>' ?>
          <span class="muted small">verificado <?= e(ago($upd['checked_at'] ?? null)) ?></span><?php endif; ?></td></tr>
      <tr><th>Último deploy</th><td><?= $st['deploy'] ? e(fmt_datetime($st['deploy']['finished_at'] ?? null) . ' — ' . ($st['deploy']['status'] ?? '') . ' (' . ($st['deploy']['release'] ?? '') . ')') : '—' ?></td></tr>
      <tr><th>Último backup</th><td><?= $st['backup'] ? e(fmt_datetime($st['backup']['finished_at'] ?? null) . ' — ' . ($st['backup']['status'] ?? '')) : '—' ?></td></tr>
    </table>
    <div class="row wrap">
      <form method="post" action="/sistema/tarefas" class="inline"><?= csrf_field() ?><input type="hidden" name="type" value="check_update"><button class="btn" <?= $running ? 'disabled' : '' ?>>VERIFICAR ATUALIZAÇÃO</button></form>
      <form method="post" action="/sistema/tarefas" class="inline"><?= csrf_field() ?><input type="hidden" name="type" value="backup"><button class="btn" <?= $running ? 'disabled' : '' ?>>Fazer backup agora</button></form>
      <form method="post" action="/sistema/tarefas" class="inline"><?= csrf_field() ?><input type="hidden" name="type" value="health"><button class="btn">Health check completo</button></form>
    </div>
  </div>
  <div class="card">
    <h2>Atualizar sistema</h2>
    <?php if ($available && $remote): ?>
      <details class="modal-like">
        <summary class="btn btn-primary btn-lg">ATUALIZAR SISTEMA</summary>
        <div class="alert alert-warning">Será realizado um backup antes da atualização. Se algum teste pós-deploy falhar, a versão anterior é restaurada automaticamente.</div>
        <form method="post" action="/sistema/tarefas" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="type" value="update">
          <label>Confirme sua senha<input type="password" name="password" required autocomplete="current-password"></label>
          <div class="row"><button class="btn btn-primary">ATUALIZAR</button><button type="button" class="btn btn-ghost" data-close-details>CANCELAR</button></div>
        </form>
      </details>
    <?php else: ?>
      <button class="btn btn-lg" disabled>ATUALIZAR SISTEMA</button>
      <p class="muted small"><?= $remote ? 'Nenhuma atualização disponível no momento.' : 'Para atualizar pelo painel, a instalação precisa ter sido feita a partir de um repositório Git (git clone). Caso contrário use sudo ./deploy/deploy.sh no servidor.' ?></p>
    <?php endif; ?>
    <p class="muted small">A atualização é executada de forma assíncrona pelo agente do servidor, com registro completo abaixo. O painel nunca executa comandos diretamente.</p>
  </div>
</div>
<div class="card">
  <h2>Tarefas</h2>
  <table class="table compact">
    <thead><tr><th>#</th><th>Tarefa</th><th>Situação</th><th>Solicitada por</th><th>Criada</th><th>Concluída</th><th>Resultado</th></tr></thead>
    <?php foreach ($jobs as $j): ?>
      <tr><td><a href="/sistema/tarefas/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td><td><?= e(App\Services\SystemJobs::TYPES[$j['type']] ?? $j['type']) ?></td>
        <td><?= status_badge($j['status']) ?></td><td><?= e($j['user_name'] ?? '—') ?></td><td><?= e(fmt_datetime($j['created_at'])) ?></td>
        <td><?= e(fmt_datetime($j['finished_at'])) ?></td><td class="small"><?= e($j['result'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="muted">Nenhuma tarefa.</td></tr><?php endif; ?>
  </table>
  <?php if ($running): ?><p class="muted small" data-autorefresh="15">Há tarefa em andamento — esta página atualiza sozinha.</p><?php endif; ?>
</div>
<div class="card">
  <h2>Histórico de deploys</h2>
  <table class="table compact">
    <thead><tr><th>Data</th><th>Tipo</th><th>Versão</th><th>Release</th><th>Situação</th><th>Duração</th><th>Backup</th><th>Mensagem</th></tr></thead>
    <?php foreach ($deployments as $d): ?>
      <tr><td><?= e(fmt_datetime($d['created_at'])) ?></td><td><?= e($d['kind']) ?></td><td><?= e($d['version']) ?></td><td class="small"><?= e($d['release_name']) ?></td>
        <td><?= status_badge($d['status']) ?></td><td><?= (int) $d['duration_s'] ?>s</td><td class="small"><?= e(basename((string) $d['backup_file'])) ?></td><td class="small"><?= e($d['message'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$deployments): ?><tr><td colspan="8" class="muted">Nenhum deploy registrado.</td></tr><?php endif; ?>
  </table>
</div>
