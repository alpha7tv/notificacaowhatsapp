<?php /** @var array $fields @var array $values */ include __DIR__ . '/_nav.php'; ?>
<div style="max-width:760px">
  <form method="post" action="/admin/meu-app/configuracoes" style="<?= $box ?>padding:22px">
    <?= csrf_field() ?>
    <?php foreach ($fields as $k => [$label, $default]): ?>
      <div style="margin:0 0 14px">
        <label style="<?= $lbl ?>" for="c-<?= e($k) ?>"><?= e($label) ?></label>
        <?php if ($k === 'expired_message'): ?>
          <textarea class="textarea" style="<?= $fld ?>" id="c-<?= e($k) ?>" name="<?= e($k) ?>" rows="3" maxlength="500"><?= e($values[$k]) ?></textarea>
        <?php else: ?>
          <input class="input" style="<?= $fld ?>" id="c-<?= e($k) ?>" name="<?= e($k) ?>" maxlength="500" value="<?= e($values[$k]) ?>">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="muted" style="margin:0 0 16px;font-size:.88rem">O <strong>servidor padrão</strong> é usado quando o cliente não tem um servidor próprio cadastrado. O <strong>código do Downloader</strong> aparece no e-mail do teste para quem instala pela TV.</p>
    <button class="btn btn-primary" type="submit">Salvar configurações</button>
  </form>
</div>
