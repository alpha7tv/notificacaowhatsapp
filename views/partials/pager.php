<?php
/** @var int $page @var int $pages @var int $total */
if (($pages ?? 1) > 1):
    $q = $_GET;
    ?>
<div class="pager">
  <span class="muted"><?= (int) $total ?> registro(s)</span>
  <?php if ($page > 1): $q['p'] = $page - 1; ?><a class="btn btn-sm" href="?<?= e(http_build_query($q)) ?>">‹ Anterior</a><?php endif; ?>
  <span>Página <?= (int) $page ?> de <?= (int) $pages ?></span>
  <?php if ($page < $pages): $q['p'] = $page + 1; ?><a class="btn btn-sm" href="?<?= e(http_build_query($q)) ?>">Próxima ›</a><?php endif; ?>
</div>
<?php else: ?>
<div class="pager"><span class="muted"><?= (int) ($total ?? 0) ?> registro(s)</span></div>
<?php endif; ?>
