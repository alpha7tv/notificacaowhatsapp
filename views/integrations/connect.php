<div class="toolbar"><a class="btn btn-ghost" href="/integracoes">‹ Integrações</a></div>

<?php if ($state === 'open'): ?>
  <div class="card card-ok narrow">
    <h2>✅ WhatsApp conectado</h2>
    <p>O número <b><?= e($owner ? mask_phone($owner) : '(não informado)') ?></b> está conectado e pronto para enviar mensagens.</p>
    <details>
      <summary class="muted small">Desconectar este número</summary>
      <form method="post" action="/integracoes/whatsapp/desconectar" class="form" data-confirm="Desconectar o WhatsApp? Nenhuma mensagem será enviada até conectar novamente.">
        <?= csrf_field() ?>
        <label>Confirme sua senha<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-danger">Desconectar WhatsApp</button>
      </form>
    </details>
  </div>
  <p><a class="btn btn-primary" href="/homologacao">Próximo passo: Homologação ›</a></p>

<?php elseif ($state === null): ?>
  <div class="card narrow">
    <h2>Evolution API não respondeu</h2>
    <p class="muted">Verifique se ela está rodando no servidor: <code>cd /opt/evolution &amp;&amp; docker compose ps</code></p>
    <a class="btn" href="/integracoes/whatsapp/conectar">Tentar de novo</a>
  </div>

<?php else: ?>
  <div class="grid-2">
    <div class="card">
      <?php if ($number && !empty($conn['pairing'])): ?>
        <h2>Digite este código no celular</h2>
        <p class="pairing-code"><?= e($conn['pairing']) ?></p>
        <ol>
          <li>No celular com o número <b><?= e(mask_phone($number)) ?></b>, abra o <b>WhatsApp</b>.</li>
          <li>Toque em <b>⋮ (Mais opções)</b> → <b>Aparelhos conectados</b> → <b>Conectar aparelho</b>.</li>
          <li>Toque em <b>Conectar com número de telefone</b> e digite o código acima.</li>
        </ol>
        <p class="muted small" data-autorefresh="60">Esta página verifica a conexão sozinha a cada minuto.</p>
      <?php elseif (!empty($conn['qr'])): ?>
        <h2>Escaneie o QR Code</h2>
        <img class="qr" src="<?= e($conn['qr']) ?>" alt="QR Code do WhatsApp" width="280" height="280">
        <ol>
          <li>No celular da empresa, abra o <b>WhatsApp</b>.</li>
          <li>Toque em <b>⋮ (Mais opções)</b> (no iPhone: <b>Configurações</b>) → <b>Aparelhos conectados</b>.</li>
          <li>Toque em <b>Conectar aparelho</b> e aponte a câmera para este QR Code.</li>
        </ol>
        <p class="muted small" data-autorefresh="25">O QR Code muda a cada ~25 segundos; esta página atualiza sozinha e mostra ✅ quando conectar.</p>
      <?php else: ?>
        <h2>Gerando QR Code…</h2>
        <p class="muted"><?= e($conn['error'] ?? 'Aguarde alguns segundos.') ?></p>
        <p class="muted small" data-autorefresh="5">Atualizando…</p>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Prefere conectar sem câmera?</h2>
      <p class="muted">Informe o número do WhatsApp da empresa para receber um código de 8 letras e digitá-lo no celular.</p>
      <form method="get" action="/integracoes/whatsapp/conectar" class="form">
        <label>Número do WhatsApp (com DDD)<input name="numero" value="<?= e($number ? substr($number, 2) : '') ?>" placeholder="(16) 99999-9999" inputmode="tel" required></label>
        <button class="btn">Gerar código</button>
      </form>
      <?php if ($number): ?><p><a href="/integracoes/whatsapp/conectar">Voltar para o QR Code</a></p><?php endif; ?>
      <hr>
      <p class="muted small">⚠ Use um número exclusivo da empresa. A conexão é pela Evolution API (WhatsApp Web, não oficial).
        Evite envios em massa rápidos para não ter o número bloqueado.</p>
    </div>
  </div>
<?php endif; ?>
