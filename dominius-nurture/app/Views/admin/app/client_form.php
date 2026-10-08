<?php /** @var ?array $client @var array $devices @var string $defaultServer */
include __DIR__ . '/_nav.php';
$c = $client ?? [];
$action = $client ? '/admin/meu-app/clientes/' . (int) $client['id'] : '/admin/meu-app/clientes';
$expires = \App\Controllers\Admin\AppAdminController::utcToLocal($c['expires_at'] ?? null);
?>
<div style="max-width:760px">
  <?php if ($client): ?>
  <div style="<?= $box ?>padding:18px 22px;margin:0 0 18px;display:flex;gap:18px;align-items:center;flex-wrap:wrap">
    <div><div class="muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Código de ativação</div>
      <div style="font-family:Consolas,monospace;font-size:2rem;font-weight:800;letter-spacing:.12em;color:#FFC94D"><?= e($c['code']) ?></div></div>
    <form method="post" action="/admin/meu-app/clientes/<?= (int) $c['id'] ?>/codigo" onsubmit="return confirm('Gerar um novo código? O antigo deixa de funcionar para novos aparelhos.');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>">Gerar novo código</button></form>
  </div>
  <?php endif; ?>
  <form method="post" action="<?= e($action) ?>" style="<?= $box ?>padding:22px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
      <div><label style="<?= $lbl ?>" for="f-name">Nome</label><input class="input" style="<?= $fld ?>" id="f-name" name="name" required maxlength="150" value="<?= e($c['name'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-email">E-mail</label><input class="input" style="<?= $fld ?>" id="f-email" name="email" type="email" maxlength="190" value="<?= e($c['email'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-phone">WhatsApp</label><input class="input" style="<?= $fld ?>" id="f-phone" name="phone" maxlength="30" value="<?= e($c['phone'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-server">Servidor (http://...)</label><input class="input" style="<?= $fld ?>" id="f-server" name="server_url" maxlength="255" placeholder="<?= e($defaultServer ?: 'http://servidor.com') ?>" value="<?= e($c['server_url'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-user">Usuário da lista</label><input class="input" style="<?= $fld ?>" id="f-user" name="username" maxlength="120" autocomplete="off" value="<?= e($c['username'] ?? '') ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-pass">Senha da lista<?= $client ? ' (deixe em branco para manter)' : '' ?></label><input class="input" style="<?= $fld ?>" id="f-pass" name="password" maxlength="120" autocomplete="new-password"></div>
      <div><label style="<?= $lbl ?>" for="f-exp">Vencimento (horário de Brasília)</label><input class="input" style="<?= $fld ?>" id="f-exp" name="expires" type="datetime-local" value="<?= e($expires) ?>"></div>
      <div><label style="<?= $lbl ?>" for="f-max">Aparelhos permitidos</label><input class="input" style="<?= $fld ?>" id="f-max" name="max_devices" type="number" min="1" max="10" value="<?= (int) ($c['max_devices'] ?? 2) ?>"></div>
    </div>
    <div style="display:flex;gap:22px;flex-wrap:wrap;margin:16px 0">
      <label><input type="checkbox" name="is_trial" value="1"<?= !empty($c['is_trial']) ? ' checked' : '' ?>> É um teste</label>
      <label><input type="checkbox" name="status" value="blocked"<?= ($c['status'] ?? '') === 'blocked' ? ' checked' : '' ?>> Bloquear o acesso</label>
    </div>
    <div style="margin:0 0 16px"><label style="<?= $lbl ?>" for="f-notes">Observações (só você vê)</label><textarea class="textarea" style="<?= $fld ?>" id="f-notes" name="notes" rows="3" maxlength="2000"><?= e($c['notes'] ?? '') ?></textarea></div>
    <button class="btn btn-primary" type="submit"><?= $client ? 'Salvar' : 'Criar cliente' ?></button>
    <a class="btn" href="/admin/meu-app/clientes">Voltar</a>
  </form>

  <?php if ($client): ?>
  <div style="<?= $box ?>padding:18px 22px;margin:18px 0 0">
    <h3 style="margin:0 0 10px">Aparelhos (<?= count($devices) ?>/<?= (int) $c['max_devices'] ?>)</h3>
    <?php if (!$devices): ?><p class="muted" style="margin:0">Nenhum aparelho ativou este código ainda.</p><?php endif; ?>
    <?php foreach ($devices as $d): ?>
      <div style="padding:8px 0;border-top:1px solid rgba(255,255,255,.08);font-size:.9rem"><strong><?= e($d['model'] ?: 'Aparelho') ?></strong> · app <?= e((string) $d['app_version']) ?> · último acesso <?= e(dt($d['last_seen_at'])) ?></div>
    <?php endforeach; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
      <?php if ($devices): ?><form method="post" action="/admin/meu-app/clientes/<?= (int) $c['id'] ?>/aparelhos/limpar" onsubmit="return confirm('Liberar todos os aparelhos deste cliente?');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>">Liberar aparelhos</button></form><?php endif; ?>
      <form method="post" action="/admin/meu-app/clientes/<?= (int) $c['id'] ?>/excluir" onsubmit="return confirm('Excluir este cliente? Isto não pode ser desfeito.');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>color:#FF4D6D;border-color:rgba(255,77,109,.5)">Excluir cliente</button></form>
    </div>
  </div>
  <?php endif; ?>
</div>
