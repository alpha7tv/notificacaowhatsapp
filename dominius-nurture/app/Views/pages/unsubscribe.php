<?php /** @var ?array $sub @var string $token @var bool $done */ ?>
<section class="section-sm">
  <div class="container" style="max-width:640px">
    <div class="state-card">
      <?php if ($done): ?>
        <div class="state-icon"><?= icon('mail-check') ?></div>
        <h3>Pronto, você não receberá mais as nossas mensagens</h3>
        <p class="muted">Cancelamos o envio dos e-mails da sequência para <?= e($sub['email'] ?? '') ?>. O seu teste e o site continuam à disposição.</p>
        <a class="btn btn-primary" href="/">Voltar ao site</a>
      <?php elseif (!$sub): ?>
        <div class="state-icon"><?= icon('info') ?></div>
        <h3>Link inválido</h3>
        <p class="muted">Não encontramos este cadastro. Se precisar de ajuda para parar de receber as mensagens, fale com a nossa equipe.</p>
        <a class="btn btn-primary" href="<?= e(support_link()) ?>">Falar com a equipe</a>
      <?php elseif ($sub['status'] !== 'active'): ?>
        <div class="state-icon"><?= icon('mail-check') ?></div>
        <h3>Você já não está recebendo nossas mensagens</h3>
        <p class="muted">Nenhuma ação é necessária.</p>
        <a class="btn btn-primary" href="/">Voltar ao site</a>
      <?php else: ?>
        <div class="state-icon"><?= icon('shield-check') ?></div>
        <h3>Parar de receber nossas mensagens?</h3>
        <p class="muted">Vamos cancelar o envio dos e-mails da sequência para <?= e($sub['email']) ?>.</p>
        <form method="post" action="/descadastrar/<?= e($token) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-primary" type="submit">Sim, parar de receber</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
