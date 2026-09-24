<?php
$events = App\Http\ConfigController::EVENTS;
$r = $edit ?? ['id' => 0, 'name' => '', 'event' => 'before_due', 'offset_days' => 3, 'template_id' => 0, 'active' => 1, 'send_start' => '08:00:00', 'send_end' => '20:00:00'];
$isAdmin = App\Core\Auth::isAdmin();
?>
<p class="muted">Cada regra cria mensagens automaticamente. Uma mesma regra nunca envia duas vezes a mesma mensagem para a mesma fatura/contrato (chave de idempotência).</p>
<div class="card">
<table class="table">
  <thead><tr><th>Regra</th><th>Quando</th><th>Modelo</th><th>Janela</th><th>Ativa</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rules as $x): ?>
    <tr>
      <td><?= e($x['name']) ?></td>
      <td><?= e(event_label($x['event'])) ?><?= in_array($x['event'], ['before_due', 'after_due'], true) ? ' — ' . (int) $x['offset_days'] . ' dia(s)' : '' ?></td>
      <td><a href="/templates/<?= (int) $x['template_id'] ?>"><?= e($x['template_name']) ?></a></td>
      <td><?= e(substr($x['send_start'], 0, 5)) ?>–<?= e(substr($x['send_end'], 0, 5)) ?></td>
      <td><?= $x['active'] ? '<span class="badge badge-ok">Sim</span>' : '<span class="badge badge-muted">Não</span>' ?></td>
      <td class="nowrap">
        <?php if ($isAdmin): ?>
        <a class="btn btn-sm" href="/regras?editar=<?= (int) $x['id'] ?>">Editar</a>
        <form method="post" action="/regras/<?= (int) $x['id'] ?>/excluir" class="inline" data-confirm="Excluir a regra &quot;<?= e($x['name']) ?>&quot;? Mensagens pendentes dela serão canceladas."><?= csrf_field() ?><button class="btn btn-sm btn-danger">Excluir</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($isAdmin): ?>
<div class="card narrow">
  <h2><?= $r['id'] ? 'Editar regra' : 'Nova regra' ?></h2>
  <form method="post" action="/regras/salvar" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <label>Nome<input name="name" value="<?= e($r['name']) ?>" required maxlength="120"></label>
    <label>Evento
      <select name="event">
        <?php foreach ($events as $ev): ?><option value="<?= $ev ?>" <?= $r['event'] === $ev ? 'selected' : '' ?>><?= e(event_label($ev)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Dias (antes do vencimento / de atraso)<input type="number" name="offset_days" min="0" max="90" value="<?= (int) $r['offset_days'] ?>"></label>
    <label>Modelo
      <select name="template_id" required>
        <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $r['template_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="row">
      <label>Enviar a partir de<input type="time" name="send_start" value="<?= e(substr($r['send_start'], 0, 5)) ?>" required></label>
      <label>até<input type="time" name="send_end" value="<?= e(substr($r['send_end'], 0, 5)) ?>" required></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>> Regra ativa</label>
    <div class="row"><button class="btn btn-primary">Salvar regra</button><?php if ($r['id']): ?><a class="btn btn-ghost" href="/regras">Cancelar</a><?php endif; ?></div>
  </form>
</div>
<?php endif; ?>
