<?php /** @var array $rows @var array $counts @var string $q @var string $status @var int $totalSteps @var bool $sequenceOn */
$labels = [
    'active' => ['Ativa', '#25D366'],
    'completed' => ['Concluída', '#8E98B0'],
    'unsubscribed' => ['Saiu', '#FF4D6D'],
    'converted' => ['Cliente', '#FFC94D'],
];
$plural = ['active' => 'Ativas', 'completed' => 'Concluídas', 'unsubscribed' => 'Pediram para sair', 'converted' => 'Viraram clientes'];
$box = 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);border-radius:14px;';
$th = 'text-align:left;padding:10px 12px;font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;opacity:.7;white-space:nowrap;border-bottom:1px solid rgba(255,255,255,.12);';
$td = 'padding:12px;vertical-align:top;border-bottom:1px solid rgba(255,255,255,.07);font-size:.92rem;';
$mini = 'padding:6px 10px;font-size:.78rem;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:transparent;color:inherit;cursor:pointer;';
?>
<div>
  <p class="muted" style="margin:0 0 16px">
    Pessoas que pediram teste e aceitaram receber mensagens de revenda. A sequência está
    <strong><?= $sequenceOn ? 'ligada' : 'desligada' ?></strong>.
  </p>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px">
    <?php foreach ($labels as $k => [$lbl, $color]): ?>
      <a href="/admin/sequencia?status=<?= e($k) ?>" style="<?= $box ?>padding:14px 18px;min-width:130px;text-decoration:none;color:inherit">
        <div style="font-size:1.6rem;font-weight:800;color:<?= e($color) ?>"><?= (int) ($counts[$k] ?? 0) ?></div>
        <div class="muted" style="font-size:.85rem"><?= e($plural[$k]) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="/admin/sequencia" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, e-mail ou telefone" style="flex:1;min-width:240px" maxlength="100">
    <select class="select" name="status" style="min-width:150px">
      <option value="">Todos os status</option>
      <?php foreach ($labels as $k => [$lbl]): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filtrar</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn" href="/admin/sequencia">Limpar</a><?php endif; ?>
  </form>

  <div style="<?= $box ?>overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:820px">
      <thead><tr>
        <th style="<?= $th ?>">Pessoa</th>
        <th style="<?= $th ?>">Status</th>
        <th style="<?= $th ?>">Etapa</th>
        <th style="<?= $th ?>">Próximo e-mail</th>
        <th style="<?= $th ?>">WhatsApp</th>
        <th style="<?= $th ?>">Entrou</th>
        <th style="<?= $th ?>">Ações</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" style="<?= $td ?>text-align:center;padding:30px" class="muted">Nenhuma inscrição encontrada.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): [$lbl, $color] = $labels[$r['status']] ?? [$r['status'], '#8E98B0']; ?>
        <tr>
          <td style="<?= $td ?>">
            <strong><?= e($r['name']) ?></strong><br>
            <span class="muted"><?= e($r['email']) ?></span><br>
            <span class="muted" style="white-space:nowrap"><?= e(phone_display($r["phone_e164"])) ?></span>
          </td>
          <td style="<?= $td ?>"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= e($color) ?>;margin-right:6px"></span><?= e($lbl) ?>
            <?php if ($r['status'] === 'unsubscribed' && $r['unsubscribed_at']): ?><br><span class="muted" style="font-size:.8rem"><?= e(dt($r['unsubscribed_at'])) ?></span><?php endif; ?>
          </td>
          <td style="<?= $td ?>white-space:nowrap"><?= (int) $r['step'] ?>/<?= (int) $totalSteps ?> e-mails</td>
          <td style="<?= $td ?>white-space:nowrap"><?= $r['status'] === 'active' && $r['next_send_at'] ? e(dt($r['next_send_at'])) : '—' ?></td>
          <td style="<?= $td ?>font-size:.85rem">
            <?= $r['whatsapp_sent_at'] ? '✓ boas-vindas' : '· boas-vindas pendente' ?><br>
            <?= $r['followup_sent_at'] ? '✓ acompanhamento 4h' : ($r['followup_at'] && $r['status'] === 'active' ? '· 4h: ' . e(dt($r['followup_at'])) : '· 4h: —') ?>
          </td>
          <td style="<?= $td ?>white-space:nowrap"><?= e(dt($r['created_at'])) ?></td>
          <td style="<?= $td ?>">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if ($r['status'] === 'active'): ?>
              <form method="post" action="/admin/sequencia/<?= (int) $r['id'] ?>/parar" onsubmit="return confirm('Parar a sequência de <?= e(addslashes($r['name'])) ?>? Ela não receberá mais mensagens nem e-mails.');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>">Parar (pediu para sair)</button></form>
              <form method="post" action="/admin/sequencia/<?= (int) $r['id'] ?>/cliente" onsubmit="return confirm('Marcar como cliente e encerrar a sequência?');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>">Virou cliente</button></form>
            <?php endif; ?>
              <form method="post" action="/admin/sequencia/<?= (int) $r['id'] ?>/excluir" onsubmit="return confirm('Excluir os dados desta pessoa da sequência? Isto não pode ser desfeito.');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>color:#FF4D6D;border-color:rgba(255,77,109,.5)">Excluir dados</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin:12px 0 0;font-size:.85rem">
    <strong>Parar</strong> mantém o registro como comprovante do pedido de saída. <strong>Excluir dados</strong> apaga tudo (use para pedidos de exclusão da LGPD).
    Mostrando até 300 registros mais recentes.
  </p>
</div>
