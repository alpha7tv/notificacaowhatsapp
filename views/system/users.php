<?php $u = $edit ?? ['id' => 0, 'name' => '', 'email' => '', 'role' => 'operador', 'active' => 1]; ?>
<div class="grid-2">
  <div class="card">
    <table class="table">
      <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th>Último acesso</th><th></th></tr></thead>
      <?php foreach ($users as $x): ?>
        <tr><td><?= e($x['name']) ?></td><td><?= e($x['email']) ?></td><td><?= $x['role'] === 'admin' ? 'Administrador' : 'Operador' ?></td>
          <td><?= $x['active'] ? '<span class="badge badge-ok">Sim</span>' : '<span class="badge badge-muted">Não</span>' ?></td>
          <td class="muted"><?= e(fmt_datetime($x['last_login_at'])) ?></td>
          <td><a class="btn btn-sm" href="/sistema/usuarios?editar=<?= (int) $x['id'] ?>">Editar</a></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card">
    <h2><?= $u['id'] ? 'Editar usuário' : 'Novo usuário' ?></h2>
    <form method="post" action="/sistema/usuarios/salvar" class="form" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <label>Nome<input name="name" value="<?= e($u['name']) ?>" required></label>
      <label>E-mail<input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
      <label>Perfil<select name="role"><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option><option value="operador" <?= $u['role'] === 'operador' ? 'selected' : '' ?>>Operador (sem acesso a Sistema/Integrações)</option></select></label>
      <label><?= $u['id'] ? 'Nova senha (deixe em branco para manter)' : 'Senha (mín. 10 caracteres)' ?><input type="password" name="password" minlength="10" <?= $u['id'] ? '' : 'required' ?> autocomplete="new-password"></label>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Ativo</label>
      <div class="row"><button class="btn btn-primary">Salvar</button><?php if ($u['id']): ?><a class="btn btn-ghost" href="/sistema/usuarios">Cancelar</a><?php endif; ?></div>
    </form>
  </div>
</div>
