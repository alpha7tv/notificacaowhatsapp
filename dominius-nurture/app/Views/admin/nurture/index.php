<?php /** @var array $rows @var array $counts @var string $q @var string $status @var int $totalSteps @var bool $sequenceOn @var array $broadcasts @var int $dailyCap @var bool $waReady */
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
    <table style="width:100%;border-collapse:collapse;min-width:900px">
      <thead><tr>
        <th style="<?= $th ?>width:34px"><input type="checkbox" id="sel-all" title="Marcar todos" aria-label="Marcar todos"></th>
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
<tr><td colspan="8" style="<?= $td ?>text-align:center;padding:30px" class="muted">Nenhuma inscrição encontrada.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): [$lbl, $color] = $labels[$r['status']] ?? [$r['status'], '#8E98B0']; ?>
        <tr>
          <td style="<?= $td ?>"><?php if ($r['status'] !== 'unsubscribed' && $r['phone_e164']): ?><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-form" class="sel-row" aria-label="Escolher <?= e($r['name']) ?>"><?php endif; ?></td>
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
  <div id="promo" style="<?= $box ?>padding:22px;margin:22px 0 0">
    <h3 style="margin:0 0 4px">Enviar promoção</h3>
    <p class="muted" style="margin:0 0 14px">Marque as pessoas na lista acima (<strong id="sel-count">0 selecionadas</strong>), escolha o canal, escreva a mensagem e envie. Só recebem quem aceitou receber ofertas e não saiu da lista.</p>
    <?php if (!$waReady): ?><p style="color:#FF4D6D;margin:0 0 12px">O WhatsApp ainda não está configurado. Veja a página WhatsApp no menu.</p><?php endif; ?>
    <form method="post" action="/admin/sequencia/promocao" id="bulk-form">
      <?= csrf_field() ?>
      <div style="margin:0 0 14px;display:flex;gap:18px;flex-wrap:wrap" role="radiogroup" aria-label="Canal de envio">
        <label style="cursor:pointer"><input type="radio" name="channel" value="whatsapp" checked> WhatsApp</label>
        <label style="cursor:pointer"><input type="radio" name="channel" value="email"> E-mail</label>
        <label style="cursor:pointer"><input type="radio" name="channel" value="both"> WhatsApp e e-mail</label>
      </div>
      <div class="field" id="promo-subject-box" style="margin:0 0 12px;display:none">
        <label for="promo-subject" style="display:block;margin-bottom:6px">Assunto do e-mail</label>
        <input class="input" id="promo-subject" name="subject" maxlength="120" placeholder="Ex.: {first}, condição especial para quem testou" style="display:block;width:100%;box-sizing:border-box">
      </div>
      <div class="field" style="margin:0 0 12px">
        <label for="promo-msg" style="display:block;margin-bottom:6px">Mensagem</label>
        <textarea class="textarea" id="promo-msg" name="message" rows="7" style="display:block;width:100%;box-sizing:border-box" maxlength="1000" placeholder="Oi, {first}! Aqui é o {sender}. Hoje liberei uma condição especial para quem já testou com a gente: ..." required></textarea>
        <span class="hint">No e-mail o texto vira uma página bonita (use *palavra* para negrito). Use <code>{first}</code> para o primeiro nome e <code>{sender}</code> para o seu nome. O aviso "se não quiser receber, é só avisar" é adicionado no final. Evite links (aumentam o risco de bloqueio do número).</span>
      </div>
      <div class="field" id="promo-button-box" style="margin:0 0 12px;display:none">
        <label for="promo-button" style="display:block;margin-bottom:6px">Texto do botão do e-mail (opcional)</label>
        <input class="input" id="promo-button" name="button" maxlength="40" placeholder="Falar no WhatsApp" style="display:block;width:100%;box-sizing:border-box;max-width:360px">
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <button class="btn btn-primary" type="submit" name="action" value="send" onclick="return confirm('Programar esta promoção para as pessoas marcadas?');">Enviar para as marcadas</button>
        <span class="muted" style="font-size:.85rem">Saem aos poucos, das 9h às 20h, até <?= (int) $dailyCap ?> por dia.</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.1)">
        <div class="field" style="margin:0;min-width:200px" id="test-phone-box">
          <label for="promo-test">Testar no meu WhatsApp</label>
          <input class="input" id="promo-test" name="test_phone" type="tel" placeholder="14 98888-7777" maxlength="30">
        </div>
        <div class="field" style="margin:0;min-width:220px;display:none" id="test-email-box">
          <label for="promo-test-email">Testar no meu e-mail</label>
          <input class="input" id="promo-test-email" name="test_email" type="email" placeholder="voce@email.com" maxlength="190">
        </div>
        <button class="btn" type="submit" name="action" value="test" formnovalidate>Enviar só um teste</button>
      </div>
    </form>
  </div>

  <?php if ($broadcasts): ?>
  <div style="<?= $box ?>padding:18px 22px;margin:18px 0 0">
    <h3 style="margin:0 0 10px">Promoções recentes</h3>
    <?php foreach ($broadcasts as $b): ?>
      <div style="padding:10px 0;border-top:1px solid rgba(255,255,255,.08);font-size:.9rem">
        <span class="muted"><?= e(dt($b['created_at'])) ?></span> ·
        <strong><?= (int) $b['sent'] ?></strong> enviadas de <?= (int) $b['total'] ?>
        <?php if ((int) $b['pending'] > 0): ?>· <?= (int) $b['pending'] ?> na fila<?php endif; ?>
        <?php if ((int) $b['failed'] > 0): ?>· <span style="color:#FF4D6D"><?= (int) $b['failed'] ?> não enviadas</span><?php endif; ?><br>
        <span class="muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.04em"><?= e(['whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'both' => 'WhatsApp + e-mail'][$b['channel'] ?? 'whatsapp'] ?? 'WhatsApp') ?></span><br>
        <span class="muted"><?= e(mb_substr((string) $b['message'], 0, 110)) ?><?= mb_strlen((string) $b['message']) > 110 ? '…' : '' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    var all = document.getElementById('sel-all'), count = document.getElementById('sel-count');
    function rows() { return document.querySelectorAll('.sel-row'); }
    function refresh() { var n = 0; rows().forEach(function (c) { if (c.checked) n++; }); count.textContent = n + (n === 1 ? ' selecionada' : ' selecionadas'); }
    if (all) all.addEventListener('change', function () { rows().forEach(function (c) { c.checked = all.checked; }); refresh(); });
    rows().forEach(function (c) { c.addEventListener('change', refresh); });
    refresh();
    // mostra os campos de e-mail só quando o canal pede
    function channel() { var r = document.querySelector('input[name="channel"]:checked'); return r ? r.value : 'whatsapp'; }
    function layout() {
      var c = channel(), mail = c !== 'whatsapp', wa = c !== 'email';
      document.getElementById('promo-subject-box').style.display = mail ? 'block' : 'none';
      document.getElementById('promo-button-box').style.display = mail ? 'block' : 'none';
      document.getElementById('test-email-box').style.display = mail ? 'block' : 'none';
      document.getElementById('test-phone-box').style.display = wa ? 'block' : 'none';
      document.getElementById('promo-subject').required = mail;
    }
    document.querySelectorAll('input[name="channel"]').forEach(function (r) { r.addEventListener('change', layout); });
    layout();
  })();
  </script>
</div>
