<?php /** @var string $state @var string $instance @var bool $sequenceOn */
$connected = $state === 'open';
$labels = [
    'open' => ['Conectado', '#25D366'],
    'connecting' => ['Aguardando leitura do QR code', '#FFC94D'],
    'close' => ['Desconectado', '#FF4D6D'],
    'not_configured' => ['Não configurado', '#8E98B0'],
    'auth' => ['Chave da instância recusada', '#FF4D6D'],
    'unknown' => ['Instância não encontrada', '#FF4D6D'],
    'error' => ['Evolution fora do ar', '#FF4D6D'],
];
[$label, $color] = $labels[$state] ?? $labels['unknown'];
$box = 'background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:22px;margin:0 0 18px;';
?>
<div style="max-width:760px">
  <p class="muted" style="margin:0 0 18px">Conexão do WhatsApp usada para a mensagem de boas-vindas da sequência de revenda.</p>

  <div style="<?= $box ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= e($color) ?>"></span>
      <strong style="font-size:1.1rem"><?= e($label) ?></strong>
      <?php if ($instance !== ''): ?><span class="muted">· instância <code><?= e($instance) ?></code></span><?php endif; ?>
    </div>
    <p class="muted" style="margin:10px 0 0">
      Sequência de e-mails e WhatsApp: <strong><?= $sequenceOn ? 'ligada' : 'desligada' ?></strong>
      <?php if (!$sequenceOn): ?>(nenhuma mensagem é enviada a clientes enquanto estiver desligada)<?php endif; ?>
    </p>
  </div>

  <?php if ($state === 'not_configured'): ?>
    <div style="<?= $box ?>"><strong>Falta configurar a instância.</strong><p class="muted" style="margin:8px 0 0">Peça para rodar o instalador do WhatsApp no servidor, que cria a instância e preenche as chaves.</p></div>

  <?php elseif (in_array($state, ['error', 'unknown', 'auth'], true)): ?>
    <div style="<?= $box ?>"><strong>Não consegui usar a Evolution.</strong><p class="muted" style="margin:8px 0 0">Pode ser que o serviço esteja fora do ar, que a instância não exista ou que a chave tenha mudado. Rode o instalador do WhatsApp de novo no servidor para refazer a configuração.</p></div>

  <?php elseif (!$connected): ?>
    <div style="<?= $box ?>text-align:center">
      <h3 style="margin:0 0 6px">Conectar o chip</h3>
      <p class="muted" style="margin:0 0 16px">No celular do chip: <strong>WhatsApp → Configurações → Aparelhos conectados → Conectar um aparelho</strong> e aponte a câmera para o código abaixo.</p>
      <div style="display:inline-block;background:#fff;padding:14px;border-radius:12px;min-width:280px;min-height:280px">
        <img src="/admin/configuracoes/whatsapp/qr?t=<?= time() ?>" width="280" height="280" alt="Gerando o QR code…" style="display:block;color:#333">
      </div>
      <p class="muted" style="margin:14px 0 0;font-size:.9rem">O código muda sozinho a cada poucos segundos e esta página atualiza automaticamente. Se ele não aparecer, aguarde 15 segundos.</p>
    </div>
    <script nonce="<?= e(csp_nonce()) ?>">setTimeout(function () { location.reload(); }, 15000);</script>

  <?php else: ?>
    <div style="<?= $box ?>">
      <h3 style="margin:0 0 10px">Enviar mensagem de teste</h3>
      <form method="post" action="/admin/configuracoes/whatsapp/testar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <div class="field" style="margin:0;flex:1;min-width:220px">
          <label for="wa-phone">Número com DDD</label>
          <input class="input" id="wa-phone" name="phone" type="tel" placeholder="14 98888-7777" required maxlength="30">
        </div>
        <button class="btn btn-primary" type="submit">Enviar teste</button>
      </form>
    </div>
    <div style="<?= $box ?>">
      <h3 style="margin:0 0 10px">Trocar de chip</h3>
      <p class="muted" style="margin:0 0 12px">Desconecta o aparelho atual. Depois é só escanear um novo QR code aqui mesmo.</p>
      <form method="post" action="/admin/configuracoes/whatsapp/desconectar" onsubmit="return confirm('Desconectar o WhatsApp atual?');">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Desconectar</button>
      </form>
    </div>
  <?php endif; ?>
</div>
