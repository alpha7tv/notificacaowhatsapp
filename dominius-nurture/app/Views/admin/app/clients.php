<?php /** @var array $rows @var string $q @var string $f */ include __DIR__ . '/_nav.php'; ?>
<div>
  <form method="get" action="/admin/meu-app/clientes" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, e-mail, código, usuário ou telefone" style="flex:1;min-width:240px" maxlength="100">
    <select class="select" name="f" style="min-width:150px">
      <option value="">Todos</option>
      <?php foreach (['trial' => 'Testes', 'expired' => 'Vencidos', 'blocked' => 'Bloqueados'] as $k => $lb): ?><option value="<?= e($k) ?>"<?= $f === $k ? ' selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filtrar</button>
    <a class="btn" href="/admin/meu-app/clientes/novo">+ Novo cliente</a>
  </form>
  <div style="<?= $box ?>overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:860px">
      <thead><tr>
        <th style="<?= $th ?>">Cliente</th><th style="<?= $th ?>">Código</th><th style="<?= $th ?>">Usuário</th><th style="<?= $th ?>">Tipo</th>
        <th style="<?= $th ?>">Vence</th><th style="<?= $th ?>">Aparelhos</th><th style="<?= $th ?>">Último acesso</th><th style="<?= $th ?>"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="<?= $td ?>text-align:center;padding:30px">Nenhum cliente encontrado.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
          $expired = $r['expires_at'] && strtotime($r['expires_at'] . ' UTC') < time(); ?>
        <tr>
          <td style="<?= $td ?>"><strong><?= e($r['name']) ?></strong><br><span class="muted"><?= e((string) $r['email']) ?></span></td>
          <td style="<?= $td ?>font-family:Consolas,monospace;font-weight:700;letter-spacing:.06em"><?= e($r['code']) ?></td>
          <td style="<?= $td ?>"><?= e((string) $r['username']) ?></td>
          <td style="<?= $td ?>"><?= $r['is_trial'] ? 'Teste' : 'Cliente' ?><?= $r['status'] === 'blocked' ? ' · <span style="color:#FF4D6D">bloqueado</span>' : '' ?></td>
          <td style="<?= $td ?>white-space:nowrap;<?= $expired ? 'color:#FF4D6D' : '' ?>"><?= $r['expires_at'] ? e(dt($r['expires_at'])) : 'sem vencimento' ?></td>
          <td style="<?= $td ?>"><?= (int) $r['devices'] ?>/<?= (int) $r['max_devices'] ?></td>
          <td style="<?= $td ?>white-space:nowrap"><?= $r['last_seen_at'] ? e(dt($r['last_seen_at'])) : '—' ?></td>
          <td style="<?= $td ?>"><a style="<?= $mini ?>" href="/admin/meu-app/clientes/<?= (int) $r['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
