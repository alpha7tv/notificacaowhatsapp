<?php $days = array_filter(explode(',', (string) ($s['send_days'] ?? '1,2,3,4,5,6'))); ?>
<div class="card narrow">
  <form method="post" action="/sistema/configuracoes" class="form">
    <?= csrf_field() ?>
    <h2>Empresa</h2>
    <label>Nome exibido nas mensagens<input name="company_name" value="<?= e($s['company_name'] ?? 'Fiber Link') ?>" maxlength="100"></label>
    <label>Telefone de suporte (variável {{telefone_suporte}})<input name="support_phone" value="<?= e($s['support_phone'] ?? '') ?>" maxlength="60"></label>
    <h2>Envio</h2>
    <div class="row">
      <label>Intervalo entre clientes: mínimo (minutos)<input type="number" name="send_interval_min_minutes" min="1" max="120" value="<?= (int) ($s['send_interval_min_minutes'] ?? 10) ?>"></label>
      <label>máximo (minutos)<input type="number" name="send_interval_max_minutes" min="1" max="180" value="<?= (int) ($s['send_interval_max_minutes'] ?? 15) ?>"></label>
    </div>
    <p class="muted small">🛡 Anti-bloqueio: entre um cliente e o próximo o sistema espera um tempo <b>aleatório</b> entre o mínimo e o máximo (padrão 10 a 15 min), espalhando os envios ao longo do dia. Com 10–15 min cabem cerca de 48 a 72 clientes por dia na janela de 08h às 20h.</p>
    <fieldset><legend>Dias permitidos para envio</legend>
      <?php foreach (['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'] as $v => $l): ?>
        <label class="check inline-check"><input type="checkbox" name="send_days[]" value="<?= $v ?>" <?= in_array($v, $days, true) ? 'checked' : '' ?>> <?= $l ?></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Junto com as cobranças (lembrete, vence hoje, atraso), enviar também:</legend>
      <label class="check"><input type="checkbox" name="attach_pdf" value="1" <?= ($s['attach_pdf'] ?? '1') === '1' ? 'checked' : '' ?>> 📄 Fatura em PDF (link do SGP)</label>
      <label class="check"><input type="checkbox" name="attach_pix_qr" value="1" <?= ($s['attach_pix_qr'] ?? '1') === '1' ? 'checked' : '' ?>> 🖼 Imagem do QR Code PIX</label>
      <label class="check"><input type="checkbox" name="attach_pix_code" value="1" <?= ($s['attach_pix_code'] ?? '1') === '1' ? 'checked' : '' ?>> 📋 Código PIX copia e cola em mensagem separada</label>
    </fieldset>
    <label>Tolerância para recuperar envios perdidos (dias)<input type="number" name="catchup_days" min="0" max="5" value="<?= (int) ($s['catchup_days'] ?? 1) ?>"></label>
    <h2>Retenção</h2>
    <label>Manter logs e webhooks por (dias)<input type="number" name="log_retention_days" min="7" max="3650" value="<?= (int) ($s['log_retention_days'] ?? 90) ?>"></label>
    <button class="btn btn-primary">Salvar configurações</button>
  </form>
</div>
