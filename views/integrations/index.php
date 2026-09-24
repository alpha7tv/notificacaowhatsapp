<?php
$isAdmin = App\Core\Auth::isAdmin();
$reveal = $webhookUrls && ($webhookUrls['until'] ?? 0) > time() ? $webhookUrls : null;
$apiBase = rtrim((string) App\Core\App::env('API_URL', ''), '/');
?>
<div class="grid-2">
  <div class="card">
    <h2>SGP <?= $sgpConfigured ? '<span class="badge badge-ok">configurado</span>' : '<span class="badge badge-muted">não configurado</span>' ?></h2>
    <form method="post" action="/integracoes/sgp" class="form">
      <?= csrf_field() ?>
      <label>URL do SGP<input name="sgp_url" value="<?= e($s['sgp_url'] ?? '') ?>" placeholder="https://suaempresa.sgp.tsmx.com.br"></label>
      <label>App (nome do aplicativo no SGP)<input name="sgp_app" value="<?= e($s['sgp_app'] ?? '') ?>"></label>
      <label>Token <?= !empty($s['sgp_token']) ? '<span class="muted small">(salvo: ' . e(App\Core\Masker::secret((string) $s['sgp_token'])) . ' — deixe em branco para manter)</span>' : '' ?>
        <input type="password" name="sgp_token" autocomplete="off" placeholder="<?= !empty($s['sgp_token']) ? '••••••••' : 'Token da API URA' ?>"></label>
      <details>
        <summary>Avançado</summary>
        <label>Endpoint de consulta de cliente<input name="sgp_customer_path" value="<?= e($s['sgp_customer_path'] ?? '/api/ura/consultacliente/') ?>"></label>
        <label>Endpoint de títulos/faturas<input name="sgp_titles_path" value="<?= e($s['sgp_titles_path'] ?? '/api/ura/titulos/') ?>"></label>
        <label>Intervalo de sincronização (minutos)<input type="number" name="sgp_sync_interval_minutes" min="5" max="1440" value="<?= e($s['sgp_sync_interval_minutes'] ?? 30) ?>"></label>
        <label>Clientes por execução<input type="number" name="sgp_sync_batch" min="10" max="5000" value="<?= e($s['sgp_sync_batch'] ?? 200) ?>"></label>
        <p class="muted small">O endpoint <code>fatura2via</code> é bloqueado: ele abre protocolo de atendimento no SGP.</p>
      </details>
      <?php if ($isAdmin): ?><button class="btn btn-primary">Salvar SGP</button><?php endif; ?>
    </form>
    <?php if ($isAdmin && $sgpConfigured): ?>
    <hr>
    <form method="post" action="/integracoes/sgp/testar" class="inline-form">
      <?= csrf_field() ?>
      <input name="document" placeholder="CPF/CNPJ de um cliente real (opcional)" inputmode="numeric">
      <button class="btn">Testar conexão</button>
    </form>
    <form method="post" action="/integracoes/sgp/sincronizar" class="inline"><?= csrf_field() ?><button class="btn">Sincronizar agora</button></form>
    <?php endif; ?>
    <?php if ($sgpSample): ?>
      <h3>Como o sistema interpretou a resposta</h3>
      <pre class="pre small"><?= e(json_encode($sgpSample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php endif; ?>
    <?php if ($lastRuns): ?>
      <h3>Últimas sincronizações</h3>
      <table class="table compact">
        <?php foreach ($lastRuns as $run): ?><tr><td><?= e(fmt_datetime($run['started_at'])) ?></td><td><?= status_badge($run['status']) ?></td><td class="muted"><?= e($run['message'] ?? '') ?></td></tr><?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>WhatsApp (Evolution API)
      <?php if (!$waConfigured): ?><span class="badge badge-muted">não configurado</span>
      <?php elseif ($waState === 'open'): ?><span class="badge badge-ok">conectado</span>
      <?php else: ?><span class="badge badge-bad"><?= e($waState ?? 'sem resposta') ?></span><?php endif; ?>
    </h2>
    <form method="post" action="/integracoes/whatsapp" class="form">
      <?= csrf_field() ?>
      <label>URL da Evolution API<input name="evolution_url" value="<?= e($s['evolution_url'] ?? '') ?>" placeholder="https://evolution.seudominio.com.br"></label>
      <label>Nome da instância<input name="evolution_instance" value="<?= e($s['evolution_instance'] ?? '') ?>"></label>
      <label>API key <?= !empty($s['evolution_apikey']) ? '<span class="muted small">(salva: ' . e(App\Core\Masker::secret((string) $s['evolution_apikey'])) . ' — deixe em branco para manter)</span>' : '' ?>
        <input type="password" name="evolution_apikey" autocomplete="off" placeholder="<?= !empty($s['evolution_apikey']) ? '••••••••' : 'apikey global ou da instância' ?>"></label>
      <label>Versão da Evolution API
        <select name="evolution_version">
          <option value="v2" <?= ($s['evolution_version'] ?? 'v2') === 'v2' ? 'selected' : '' ?>>v2 (recomendada)</option>
          <option value="v1" <?= ($s['evolution_version'] ?? '') === 'v1' ? 'selected' : '' ?>>v1</option>
        </select></label>
      <?php if ($isAdmin): ?><button class="btn btn-primary">Salvar WhatsApp</button><?php endif; ?>
    </form>
    <?php if ($isAdmin && $waConfigured): ?>
    <hr>
    <p><a class="btn btn-primary btn-lg" href="/integracoes/whatsapp/conectar"><?= $waState === 'open' ? '✅ WhatsApp conectado — ver detalhes' : '📱 Conectar WhatsApp (QR Code)' ?></a></p>
    <div class="row wrap">
      <form method="post" action="/integracoes/whatsapp/testar" class="inline"><?= csrf_field() ?><button class="btn">Testar conexão</button></form>
      <form method="post" action="/homologacao/validar-numero" class="inline"><?= csrf_field() ?><input type="hidden" name="back" value="integracoes"><button class="btn">Validar número</button></form>
      <form method="post" action="/integracoes/whatsapp/webhook" class="inline" data-confirm="Configurar o webhook da instância para apontar para este sistema?"><?= csrf_field() ?><button class="btn">Configurar webhook</button></form>
    </div>
    <?php endif; ?>
    <p class="muted small">⚠ A Evolution API usa o WhatsApp Web (não oficial). Envios em massa podem levar ao bloqueio do número; mantenha o intervalo entre mensagens em SISTEMA &gt; CONFIGURAÇÕES.</p>
  </div>
</div>

<div class="card" id="webhooks">
  <h2>Endereços de webhook</h2>
  <table class="kv">
    <tr><th>SGP</th><td class="mono"><?= $reveal ? e($reveal['sgp']) : e($apiBase . '/api/v1/webhooks/sgp?token=••••••') ?></td></tr>
    <tr><th>WhatsApp</th><td class="mono"><?= $reveal ? e($reveal['whatsapp']) : e($apiBase . '/api/v1/webhooks/whatsapp?token=••••••') ?></td></tr>
  </table>
  <?php if ($reveal): ?>
    <p class="muted small">Endereços completos visíveis por 5 minutos. Não compartilhe: o token autoriza o envio de eventos.</p>
  <?php elseif ($isAdmin): ?>
  <form method="post" action="/integracoes/webhooks/revelar" class="inline-form">
    <?= csrf_field() ?>
    <input type="password" name="password" placeholder="Sua senha" required autocomplete="current-password">
    <button class="btn">Mostrar endereços completos</button>
  </form>
  <?php endif; ?>
  <p class="muted small">O SGP pode enviar o token no endereço (<code>?token=</code>), no cabeçalho <code>X-Webhook-Token</code>, como <code>Authorization: Bearer</code> ou assinar o corpo com <code>X-Signature: sha256=HMAC</code>.</p>
</div>
