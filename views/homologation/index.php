<?php
$homolog = ($s['mode'] ?? 'homologation') !== 'production';
$isAdmin = App\Core\Auth::isAdmin();
?>
<div class="card <?= $homolog ? 'card-warn' : 'card-ok' ?>">
  <?php if ($homolog): ?>
    <h2>⚠ SISTEMA EM MODO HOMOLOGAÇÃO</h2>
    <p>Nenhuma mensagem é enviada a clientes reais. Todos os disparos são redirecionados para o número de teste
      (<?= !empty($s['test_number']) ? '<b>' . e(mask_phone($s['test_number'])) . '</b>' : '<b class="text-bad">não configurado — nada será enviado</b>' ?>),
      com limite de <?= (int) ($s['homologation_daily_limit'] ?? 30) ?> mensagem(ns) por dia (<?= (int) $sentHomolog ?> enviada(s) hoje).</p>
  <?php else: ?>
    <h2>✅ SISTEMA EM PRODUÇÃO</h2>
    <p>As mensagens estão sendo enviadas aos clientes reais desde <?= e(fmt_datetime($s['production_released_at'] ?? null)) ?>.</p>
    <?php if ($isAdmin): ?>
    <form method="post" action="/homologacao/voltar" data-confirm="Voltar ao modo homologação? Os envios para clientes reais serão interrompidos imediatamente."><?= csrf_field() ?><button class="btn btn-danger">Voltar para homologação</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Número de teste</h2>
    <form method="post" action="/homologacao/salvar" class="form">
      <?= csrf_field() ?>
      <label>WhatsApp de teste (DDD + número)<input name="test_number" value="<?= e($s['test_number'] ?? '') ?>" placeholder="(11) 98765-4321" inputmode="tel"></label>
      <label>Limite diário de mensagens na homologação<input type="number" name="homologation_daily_limit" min="0" max="1000" value="<?= (int) ($s['homologation_daily_limit'] ?? 30) ?>"></label>
      <?php if ($isAdmin): ?><button class="btn btn-primary">Salvar</button><?php endif; ?>
    </form>
    <h3>Testes de envio</h3>
    <p class="muted small">Enviam uma mensagem com dados fictícios ao número de teste usando o modelo real de cada evento.</p>
    <div class="row wrap">
      <?php foreach ($tests as $code => [$label, $ev]): ?>
        <form method="post" action="/homologacao/teste" class="inline"><?= csrf_field() ?><input type="hidden" name="code" value="<?= e($code) ?>"><button class="btn btn-sm">Enviar teste: <?= e(event_label($ev)) ?></button></form>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>Checklist de liberação para produção</h2>
    <ul class="checklist">
      <?php foreach ($items as $i): ?>
        <li class="<?= $i['ok'] ? 'ok' : 'pending' ?>"><span class="ck"><?= $i['ok'] ? '✔' : '✖' ?></span> <b><?= e($i['label']) ?></b><br><span class="muted small"><?= e($i['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($homolog && $isAdmin): ?>
      <?php if ($allOk): ?>
      <form method="post" action="/homologacao/liberar" class="form" data-confirm="ATENÇÃO: a partir de agora as mensagens serão enviadas aos CLIENTES REAIS. Continuar?">
        <?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="keep_pending" value="1" checked> Manter as <?= (int) $pendingCount ?> mensagem(ns) pendente(s) e enviá-las aos clientes (espaçadas pelo intervalo anti-bloqueio). Desmarque para cancelá-las.</label>
        <label>Digite LIBERAR para confirmar<input name="confirm" required pattern="LIBERAR" autocomplete="off"></label>
        <label>Sua senha<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-lg">LIBERAR PRODUÇÃO</button>
      </form>
      <?php else: ?>
        <button class="btn btn-lg" disabled>LIBERAR PRODUÇÃO</button>
        <p class="muted small">Todos os itens precisam estar ✔ para liberar.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
